// Central dayjs instance with the plugins the app relies on.
// Import this everywhere instead of the bare 'dayjs' package so plugins are
// guaranteed to be registered exactly once.
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import isoWeek from "dayjs/plugin/isoWeek";
import customParseFormat from "dayjs/plugin/customParseFormat";

dayjs.extend(utc);
dayjs.extend(isoWeek);
dayjs.extend(customParseFormat);

export default dayjs;
