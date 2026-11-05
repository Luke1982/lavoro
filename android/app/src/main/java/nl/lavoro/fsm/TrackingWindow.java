package nl.lavoro.fsm;

import android.content.Intent;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.Arrays;
import java.util.Calendar;
import java.util.Locale;

/**
 * The hours in which a company shares its mechanics' locations, as an
 * administrator set them under Algemene instellingen. The same rule as
 * is_within_window() in useLocationTracker.js: an ISO weekday in the list, and
 * a local time from the start up to, not including, the end.
 *
 * Without a window everything counts as inside it. That is only the case for
 * a page that does not send one yet.
 */
final class TrackingWindow {

    static final TrackingWindow ALWAYS = new TrackingWindow(null, null, new int[0]);

    private static final String[] DAY_NAMES = { "ma", "di", "wo", "do", "vr", "za", "zo" };

    private static final String START_KEY = "window_start";
    private static final String END_KEY = "window_end";
    private static final String DAYS_KEY = "window_days";

    private final String start;
    private final String end;
    private final int[] days;

    private TrackingWindow(String start, String end, int[] days) {
        this.start = start;
        this.end = end;
        this.days = days;
        Arrays.sort(this.days);
    }

    static TrackingWindow from(JSONObject object) {
        if (object == null) {
            return ALWAYS;
        }

        String start = object.optString("start", null);
        String end = object.optString("end", null);
        JSONArray list = object.optJSONArray("days");

        if (start == null || end == null || list == null) {
            return ALWAYS;
        }

        int[] days = new int[list.length()];
        for (int i = 0; i < list.length(); i++) {
            days[i] = list.optInt(i);
        }

        return new TrackingWindow(start, end, days);
    }

    static TrackingWindow from(Intent intent) {
        if (intent == null || intent.getStringExtra(START_KEY) == null) {
            return null;
        }

        int[] days = intent.getIntArrayExtra(DAYS_KEY);

        return new TrackingWindow(intent.getStringExtra(START_KEY), intent.getStringExtra(END_KEY), days == null ? new int[0] : days);
    }

    static TrackingWindow load(SharedPreferences prefs) {
        String start = prefs.getString(START_KEY, null);

        if (start == null) {
            return ALWAYS;
        }

        String[] parts = prefs.getString(DAYS_KEY, "").split(",");
        int[] days = new int[parts.length];
        for (int i = 0; i < parts.length; i++) {
            try {
                days[i] = Integer.parseInt(parts[i].trim());
            } catch (NumberFormatException e) {
                days[i] = 0;
            }
        }

        return new TrackingWindow(start, prefs.getString(END_KEY, null), days);
    }

    void putInto(Intent intent) {
        if (is_always()) {
            return;
        }

        intent.putExtra(START_KEY, start);
        intent.putExtra(END_KEY, end);
        intent.putExtra(DAYS_KEY, days);
    }

    void save(SharedPreferences prefs) {
        SharedPreferences.Editor editor = prefs.edit();

        if (is_always()) {
            editor.remove(START_KEY).remove(END_KEY).remove(DAYS_KEY);
        } else {
            StringBuilder list = new StringBuilder();
            for (int day : days) {
                list.append(list.length() > 0 ? "," : "").append(day);
            }
            editor.putString(START_KEY, start).putString(END_KEY, end).putString(DAYS_KEY, list.toString());
        }

        editor.apply();
    }

    boolean contains(Calendar now) {
        if (is_always()) {
            return true;
        }

        int day_of_week = now.get(Calendar.DAY_OF_WEEK);
        int iso_day = day_of_week == Calendar.SUNDAY ? 7 : day_of_week - 1;

        if (Arrays.binarySearch(days, iso_day) < 0) {
            return false;
        }

        String time = String.format(Locale.US, "%02d:%02d", now.get(Calendar.HOUR_OF_DAY), now.get(Calendar.MINUTE));

        return time.compareTo(start) >= 0 && time.compareTo(end) < 0;
    }

    /**
     * "op ma t/m vr van 07:00 tot 18:00", for the disclosure and the
     * notification.
     */
    String describe() {
        if (is_always()) {
            return "op elk moment van de dag";
        }

        return "op " + describe_days() + " van " + start + " tot " + end;
    }

    private String describe_days() {
        StringBuilder names = new StringBuilder();
        for (int day : days) {
            if (day >= 1 && day <= 7) {
                names.append(names.length() > 0 ? ", " : "").append(DAY_NAMES[day - 1]);
            }
        }

        boolean consecutive = days.length >= 3;
        for (int i = 1; i < days.length && consecutive; i++) {
            consecutive = days[i] == days[i - 1] + 1;
        }

        if (consecutive && days[0] >= 1 && days[days.length - 1] <= 7) {
            return DAY_NAMES[days[0] - 1] + " t/m " + DAY_NAMES[days[days.length - 1] - 1];
        }

        return names.length() > 0 ? names.toString() : "geen enkele dag";
    }

    private boolean is_always() {
        return start == null || end == null;
    }
}
