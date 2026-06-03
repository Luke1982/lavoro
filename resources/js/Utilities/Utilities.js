import dayjs from '@/Utilities/dayjs';

export const nlDate = (date) => dayjs(date).format("DD-MM-YYYY");

export const nlDateOrEmpty = (date) => {
    if (!date || date === "0000-00-00") return "";
    const parsed = dayjs(date);
    if (!parsed.isValid() || parsed.year() < 1900) return "";
    return parsed.format("DD-MM-YYYY");
};

/** Parse a "YYYY-MM-DD" (or ISO) string into a local-midnight Date. */
export const parseYmd = (str) =>
    dayjs(String(str).slice(0, 10), "YYYY-MM-DD").toDate();

export const formatLocalDateAsISO = (date) => dayjs(date).format("YYYY-MM-DD");

export const formatUtcDatetime = (date) =>
    dayjs(date).utc().format("YYYY-MM-DD HH:mm:ss");

export const localToUtcDatetime = (dateStr, timeStr) =>
    dayjs(`${dateStr}T${timeStr}`).utc().format("YYYY-MM-DD HH:mm:ss");

export const nlTime = (date) => dayjs(date).format("HH:mm");

export const mapsLinkFromCustomer = (customer) => {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
        customer.address + " " + customer.postal_code + " " + customer.city,
    )}`;
};

import { usePage } from "@inertiajs/vue3";

export const hasPermission = (permission) => {
    const page = usePage();
    const auth = page?.props?.auth || {};
    if (auth.isAdmin) {
        return true;
    }
    const perms = new Set(auth.permissions || []);
    return perms.has(permission);
};

export const hasAnyPermission = (permissions) => {
    const page = usePage();
    const auth = page?.props?.auth || {};
    if (auth.isAdmin) {
        return true;
    }
    const perms = new Set(auth.permissions || []);
    if (Array.isArray(permissions)) {
        return permissions.some((p) => perms.has(p));
    }
    return perms.has(permissions);
};

export const serviceOrderSentState = (order) => {
    if (!order) return "none";
    const a = !!order.sent_to_administration;
    const c = !!order.sent_to_customer;
    if (a && c) return "both";
    if (a && !c) return "administration";
    if (!a && c) return "customer";
    return "none";
};

export const serviceOrderPillText = (order) => {
    switch (serviceOrderSentState(order)) {
        case "both":
            return "Verzonden klant & administratie";
        case "administration":
            return "Verzonden administratie";
        case "customer":
            return "Verzonden klant";
        default:
            return "Niet verzonden";
    }
};

export const serviceOrderPillColorClasses = (order) => {
    switch (serviceOrderSentState(order)) {
        case "both":
        case "administration":
            return "bg-green-100 text-green-700 border-green-300 dark:bg-green-900/30 dark:text-green-300 dark:border-green-700/50";
        case "customer":
            return "bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700/50";
        default:
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
    }
};

export const initials = (name = "") => {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    return (
        parts
            .slice(0, 2)
            .map((p) => p[0])
            .join("")
            .toUpperCase() || "US"
    );
};

export const projectStatusClass = (status) => {
    const map = {
        "Niet gestart": "text-gray-500 dark:text-slate-400",
        Gestart: "text-blue-600 dark:text-blue-400",
        Afgerond: "text-green-600 dark:text-green-400",
        Geannuleerd: "text-red-600 dark:text-red-400",
    };
    return map[status] || "";
};

export const formatProductSalePeriod = (startDate, endDate) => {
    const start = nlDateOrEmpty(startDate);
    const end = nlDateOrEmpty(endDate);

    if (!start && !end) return "";
    if (start && !end) {
        return `van ${start} tot nu`;
    }
    if (!start && end) {
        return `tot ${end}`;
    }
    return `Verkocht tussen ${start} en ${end}`;
};

export const todayIso = () => new Date().toISOString().slice(0, 10);

const _currencyFormatter = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' });
export const nlCurrency = (value) => _currencyFormatter.format(Number(value) || 0);

export const nextServiceIso = (product, fallbackDays = 365) => {
    const days =
        product?.typical_certificate_days ??
        product?.product_type_typical_certificate_days ??
        fallbackDays;
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
};
