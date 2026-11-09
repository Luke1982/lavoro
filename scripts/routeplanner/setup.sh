#!/usr/bin/env bash
#
# Runs the route planner on this machine instead of at HeiGIT: OSRM knows the
# roads, VROOM works out the order, both in Docker. Lavoro then only needs
#
#   ROUTEPLANNER_URL=http://127.0.0.1:3000/
#
# and no ROUTEPLANNER_KEY. No daily limit, no cap on the number of stops, and
# no address or coordinate leaves the server.
#
#   scripts/routeplanner/setup.sh                            # data in /srv/lavoro-routeplanner
#   scripts/routeplanner/setup.sh --data=/var/lib/routes     # elsewhere
#   scripts/routeplanner/setup.sh --port=3100                # VROOM on another port
#   scripts/routeplanner/setup.sh --region=europe/belgium    # another Geofabrik extract
#
# Run it again to bring the map up to date; weekly is plenty, for instance
#
#   15 3 * * 0  /var/www/lavoro/scripts/routeplanner/setup.sh >> /var/log/lavoro-routeplanner.log 2>&1
#
# The new map is prepared beside the running one and only swapped in once it is
# complete, so a download or preparation that fails leaves the planner as it
# was, and a new map that gives no route is swapped out again for the old one.
# The swap itself takes the planner away for the seconds OSRM needs to load the
# map.
#
# Measured for the Netherlands (November 2026): preparing peaks at 6 GB of
# memory and takes under three minutes on 12 cores, after a download of 1.4 GB.
# Running, OSRM keeps 0.9 GB and VROOM 40 MB. The map takes 1.4 GB of disk; an
# update needs about 4.5 GB, with the download, the new map and the old one.
#
# VROOM listens on 127.0.0.1 only: it has no login of its own, so it must not be
# reachable from outside.
#
# Only roads inside the region exist. An address across the border is moved to
# the nearest road inside it without a word, so customers in Belgium or Germany
# need a region that covers them.
set -euo pipefail

DATA=/srv/lavoro-routeplanner
REGION=europe/netherlands
PORT=3000

for arg in "$@"; do
    case "$arg" in
        --data=*) DATA="${arg#*=}" ;;
        --region=*) REGION="${arg#*=}" ;;
        --port=*) PORT="${arg#*=}" ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

OSRM_IMAGE=ghcr.io/project-osrm/osrm-backend:v26.4.0
VROOM_IMAGE=ghcr.io/vroom-project/vroom-docker:v1.15.0
NETWORK=lavoro-routeplanner
MAP=$(basename "$REGION")
SOURCE="https://download.geofabrik.de/$REGION-latest.osm.pbf"
NEXT="$DATA/map.next"

say() { echo "[$(date '+%F %T')] $*"; }

osrm() { docker run --rm -u "$(id -u):$(id -g)" -v "$NEXT:/data" "$OSRM_IMAGE" "$@"; }

# Both containers afresh, on whatever is in $DATA/map now.
start() {
    docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null
    docker rm -f lavoro-osrm lavoro-vroom >/dev/null 2>&1 || true
    docker run -d --name lavoro-osrm --network "$NETWORK" --restart unless-stopped \
        -v "$DATA/map:/data:ro" "$OSRM_IMAGE" osrm-routed --algorithm mld "/data/$MAP.osrm" >/dev/null
    docker run -d --name lavoro-vroom --network "$NETWORK" --restart unless-stopped \
        -p "127.0.0.1:$PORT:3000" -v "$DATA/vroom:/conf" -e VROOM_ROUTER=osrm "$VROOM_IMAGE" >/dev/null
}

# Whether VROOM plans a route within two minutes: OSRM needs a moment to load.
# VROOM looks for a road only within 35 km of each point, so the route goes to
# the road OSRM finds nearest to 0,0 -- inside the region, wherever that lies.
answers() {
    local road probe
    for _ in $(seq 1 60); do
        road=$(docker exec lavoro-vroom curl -fsS "http://lavoro-osrm:5000/nearest/v1/driving-car/0,0" 2>/dev/null \
            | grep -o '"location":\[[^]]*\]' | cut -d: -f2) || road=''
        probe='{"jobs":[{"id":1,"location":'"$road"'}],"vehicles":[{"id":1,"profile":"driving-car","start":'"$road"',"end":'"$road"'}]}'
        if [ -n "$road" ] && curl -fsS -H 'Content-Type: application/json' -d "$probe" "http://127.0.0.1:$PORT/" 2>/dev/null \
            | grep -q '"code":0'; then
            return 0
        fi
        sleep 2
    done
    return 1
}

mkdir -p "$DATA/vroom"
exec 9>"$DATA/.lock"
flock -n 9 || { echo "Another run is already busy in $DATA." >&2; exit 1; }

rm -rf "$NEXT"
mkdir -p "$NEXT"

say "Downloading $SOURCE"
curl -fsSL -o "$NEXT/$MAP.osm.pbf" "$SOURCE"
expected=$(curl -fsSL "$SOURCE.md5" | cut -d' ' -f1)
actual=$(md5sum "$NEXT/$MAP.osm.pbf" | cut -d' ' -f1)
if [ "$expected" != "$actual" ]; then
    echo "The download does not match its checksum ($actual instead of $expected); the running map stays." >&2
    exit 1
fi

say "Preparing the roads"
osrm osrm-extract -p /opt/car.lua "/data/$MAP.osm.pbf"
osrm osrm-partition "/data/$MAP.osrm"
osrm osrm-customize "/data/$MAP.osrm"
rm "$NEXT/$MAP.osm.pbf"

cat > "$DATA/vroom/config.yml" <<EOF
cliArgs:
  geometry: false
  planmode: false
  threads: 4
  explore: 5
  limit: '1mb'
  logdir: '/..'
  logsize: '100M'
  maxlocations: 1000
  maxvehicles: 200
  override: true
  path: ''
  port: 3000
  router: 'osrm'
  timeout: 300000
  baseurl: '/'
routingServers:
  osrm:
    driving-car:
      host: 'lavoro-osrm'
      port: '5000'
EOF

say "Swapping in the new map"
rm -rf "$DATA/map.old"
if [ -d "$DATA/map" ]; then
    mv "$DATA/map" "$DATA/map.old"
fi
mv "$NEXT" "$DATA/map"
start

say "Waiting for a first route"
if answers; then
    rm -rf "$DATA/map.old"
    say "Ready. In Lavoro's .env: ROUTEPLANNER_URL=http://127.0.0.1:$PORT/ (and no ROUTEPLANNER_KEY)"
    exit 0
fi

if [ ! -d "$DATA/map.old" ]; then
    echo "VROOM gives no route after two minutes; see 'docker logs lavoro-osrm' and 'docker logs lavoro-vroom'." >&2
    exit 1
fi

echo "The new map gives no route after two minutes; going back to the previous one." >&2
rm -rf "$DATA/map.failed"
mv "$DATA/map" "$DATA/map.failed"
docker logs lavoro-osrm > "$DATA/map.failed/osrm.log" 2>&1 || true
docker logs lavoro-vroom > "$DATA/map.failed/vroom.log" 2>&1 || true
mv "$DATA/map.old" "$DATA/map"
start

if answers; then
    echo "The previous map runs again. The new one and its logs are in $DATA/map.failed." >&2
else
    echo "The previous map gives no route either; see 'docker logs lavoro-osrm' and 'docker logs lavoro-vroom'." >&2
fi
exit 1
