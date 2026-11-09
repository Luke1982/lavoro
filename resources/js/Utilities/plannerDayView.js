/**
 * The begin and end hour of the planner's day view, which each browser keeps for
 * itself. The planner writes them; other screens that work with "the working day"
 * read them, so the day someone set up there is the day they get everywhere.
 */
export const DAY_START_STORAGE_KEY = 'lavoro_planner_day_start';
export const DAY_END_STORAGE_KEY = 'lavoro_planner_day_end';

const storedHour = (key) => {
    const hour = parseInt(localStorage.getItem(key), 10);
    return Number.isInteger(hour) && hour >= 0 && hour <= 24 ? hour : null;
};

const asTime = (hour) => (hour === 24 ? '23:59' : `${String(hour).padStart(2, '0')}:00`);

/**
 * The planner's day as { start: 'HH:MM', end: 'HH:MM' }. An hour this browser
 * never set comes from `fallback`, like it does in the planner; a day that ends
 * before it starts is no day, and then all of `fallback` is used. The planner's
 * "24:00" becomes 23:59: a time field knows no 24.
 */
export const plannerWorkday = (fallback) => {
    const start = storedHour(DAY_START_STORAGE_KEY);
    const end = storedHour(DAY_END_STORAGE_KEY);
    const day = {
        start: start === null ? fallback.start : asTime(start),
        end: end === null ? fallback.end : asTime(end),
    };
    return day.start < day.end ? day : fallback;
};
