/**
 * One hue per chapter type, shared by every SectionHeader in the app so a
 * section is recognisable by colour before its title is read.
 *
 * Types that can never appear on screen together may share a hue — the palette
 * is deliberately kept small enough that the hues stay tellable apart, which
 * matters more than giving every type a private colour. Pass the key as the
 * `chapter` prop rather than a raw `color`, so an assignment lives here only.
 */
export const CHAPTER_COLORS = {
    /** The record's own data: Details, Contractgegevens, Productinformatie, … */
    details: 'indigo',
    serviceorders: 'sky',
    inspections: 'green',
    tickets: 'red',
    materials: 'amber',
    attributes: 'amber',
    timeline: 'gray',
    events: 'orange',
    milestones: 'orange',
    frequency: 'orange',
    remarks: 'violet',
    products: 'violet',
    documents: 'blue',
    photos: 'rose',
    signoff: 'teal',
    contracts: 'teal',
    financial: 'emerald',
    customer: 'cyan',
    suppliers: 'cyan',
    assets: 'cyan',
};
