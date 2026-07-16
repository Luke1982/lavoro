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

export const ticketStatusClasses = (status) => {
    const s = (status || "").toLowerCase();
    if (s === "open") return "bg-red-50 text-red-700 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-700/50";
    if (s === "in behandeling") return "bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-700/50";
    if (s === "gesloten") return "bg-green-50 text-green-700 ring-green-200 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-700/50";
    return "bg-gray-50 text-gray-700 ring-gray-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60";
};

export const ticketPriorityClasses = (priority) => {
    if (!priority) return "bg-gray-100 text-gray-700 ring-gray-300 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60";
    const p = priority.toLowerCase();
    if (p === "hoog") return "bg-red-100 text-red-700 ring-red-300 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-700/50";
    if (p === "normaal") return "bg-yellow-100 text-yellow-700 ring-yellow-300 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-700/50";
    if (p === "laag") return "bg-green-100 text-green-700 ring-green-300 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-700/50";
    return "bg-gray-100 text-gray-700 ring-gray-300 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60";
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

export const roleInitials = (name = "") =>
    String(name)
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => word[0])
        .join("")
        .slice(0, 3)
        .toUpperCase();

export const eventStatusBadgeClass = (status) => {
    const base = "inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium";
    switch (status) {
        case "Gepland":
            return base + " bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800";
        case "Gaande":
            return base + " bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800";
        case "Afgerond":
            return base + " bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800";
        case "Geannuleerd":
            return base + " bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800";
        default:
            return base + " bg-gray-100 text-gray-600 border-gray-200 dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600";
    }
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

export const maintenanceContractStatusText = (status) => {
    switch (status) {
        case "actief":
            return "Actief";
        case "toekomstig":
            return "Toekomstig";
        case "verlopen":
            return "Verlopen";
        case "geannuleerd":
            return "Geannuleerd";
        default:
            return status || "";
    }
};

export const maintenanceContractStatusClasses = (status) => {
    switch (status) {
        case "actief":
            return "bg-green-100 text-green-700 border-green-300 dark:bg-green-900/30 dark:text-green-300 dark:border-green-700/50";
        case "toekomstig":
            return "bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700/50";
        case "verlopen":
            return "bg-orange-100 text-orange-700 border-orange-300 dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-700/50";
        case "geannuleerd":
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
        default:
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
    }
};

// Maps to BadgeComponent's `color` prop (a fixed palette of named colors),
// not a raw class string like maintenanceContractStatusClasses above.
export const maintenanceContractStatusBadgeColor = (status) => {
    switch (status) {
        case "actief":
            return "green";
        case "toekomstig":
            return "blue";
        case "verlopen":
            return "orange";
        case "geannuleerd":
            return "gray";
        default:
            return "gray";
    }
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

/**
 * Shape an asset into the object AssetSelectMenu expects.
 * Exposes brand, model, serial_number and location explicitly so the
 * component's search can filter on them precisely.
 */
export function mapAssetForSelect(asset) {
    const brand = asset.product?.brand?.name ?? '';
    const model = asset.product?.model ?? '';
    return {
        id: asset.id,
        name: `${brand} ${model}`.trim() || asset.serial_number || `Machine #${asset.id}`,
        brand,
        model,
        category: asset.product?.product_type?.name ?? null,
        article_number: asset.product?.part_no ?? null,
        serial_number: asset.serial_number,
        is_bundle: !!asset.product?.bundle,
        next_service_date: asset.next_service_date,
        location: asset.location
            ? { id: asset.location.id, title: asset.location.title, city: asset.location.city }
            : null,
        thumbnail_url: asset.product?.images?.length > 0 ? `/storage/${asset.product.images[0]?.path}` : null,
    };
}
