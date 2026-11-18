import dayjs from "@/Utilities/dayjs";

/**
 * How every planner marks a holiday: its name, the band over the day and the day.
 */
export const HOLIDAY_LABEL = "text-rose-600 dark:text-rose-400";
export const HOLIDAY_BAND = "bg-rose-50/95 dark:bg-rose-950/60";
export const HOLIDAY_TINT = "bg-rose-50/50 dark:bg-rose-950/15";

const namesByYear = new Map();

function dateOf(year, month, day) {
    return dayjs(new Date(year, month - 1, day));
}

/**
 * Easter Sunday by the anonymous Gregorian algorithm (Meeus/Jones/Butcher).
 */
function easterSunday(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const n = h + l - 7 * m + 114;
    return dateOf(year, Math.floor(n / 31), (n % 31) + 1);
}

/**
 * King's Day is 27 April from 2014 on, Queen's Day was 30 April from 1980 to
 * 2013; on a Sunday either one moves to the Saturday before.
 */
function monarchsDay(year) {
    const [name, day] = year >= 2014 ? ["Koningsdag", 27] : ["Koninginnedag", 30];
    const date = dateOf(year, 4, day);
    return { name, date: date.day() === 0 ? date.subtract(1, "day") : date };
}

function holidaysOf(year) {
    const easter = easterSunday(year);
    return [
        { name: "Nieuwjaarsdag", date: dateOf(year, 1, 1) },
        { name: "Goede Vrijdag", date: easter.subtract(2, "day") },
        { name: "Eerste paasdag", date: easter },
        { name: "Tweede paasdag", date: easter.add(1, "day") },
        monarchsDay(year),
        { name: "Bevrijdingsdag", date: dateOf(year, 5, 5) },
        { name: "Hemelvaartsdag", date: easter.add(39, "day") },
        { name: "Eerste pinksterdag", date: easter.add(49, "day") },
        { name: "Tweede pinksterdag", date: easter.add(50, "day") },
        { name: "Eerste kerstdag", date: dateOf(year, 12, 25) },
        { name: "Tweede kerstdag", date: dateOf(year, 12, 26) },
    ];
}

function namesOf(year) {
    if (!namesByYear.has(year)) {
        const names = new Map();
        for (const { name, date } of holidaysOf(year)) {
            const iso = date.format("YYYY-MM-DD");
            names.set(iso, names.has(iso) ? `${names.get(iso)} en ${name}` : name);
        }
        namesByYear.set(year, names);
    }
    return namesByYear.get(year);
}

/**
 * The Dutch public holiday on a YYYY-MM-DD day, or null. Two on one day, like
 * Bevrijdingsdag and Hemelvaartsdag in 2016, come back as one name.
 */
export function publicHolidayOn(iso) {
    return namesOf(Number(iso.slice(0, 4))).get(iso) ?? null;
}

/**
 * `count` consecutive days from `start` as { date, iso, holiday }: the columns
 * of a planner week or day.
 */
export function plannerDays(start, count) {
    const first = dayjs(start);
    return Array.from({ length: count }, (_, i) => {
        const day = first.add(i, "day");
        const iso = day.format("YYYY-MM-DD");
        return { date: day.toDate(), iso, holiday: publicHolidayOn(iso) };
    });
}
