# Contacts Feature Design

**Date:** 2026-06-05
**Approach:** Polymorphic morph-pivot (Option C) — `contactables` table, extensible to any future model

---

## Database

### `contacts` table
| column | type | notes |
|---|---|---|
| `id` | bigIncrements | |
| `first_name` | string | required |
| `last_name` | string | required |
| `email` | string, nullable | |
| `timestamps` | | |

### `contactables` pivot table
| column | type | notes |
|---|---|---|
| `contact_id` | foreignIdFor(Contact) | cascade delete |
| `contactable_type` | string | morph type |
| `contactable_id` | unsignedBigInteger | morph id |
| `timestamps` | | |

Unique constraint on `(contact_id, contactable_type, contactable_id)`.

---

## Backend

### Model: `Contact`
- Fillable: `first_name`, `last_name`, `email`
- `customers()` — `morphedByMany(Customer::class, 'contactable')`
- Accessor `full_name` — `"{$this->first_name} {$this->last_name}"`

### Model: `Customer` (addition)
- `contacts()` — `morphToMany(Contact::class, 'contactable')`

### Policy: `ContactPolicy`
| method | permission checked |
|---|---|
| `viewAny(User)` | `contact.read` |
| `view(User, Contact)` | `contact.read` |
| `create(User)` | `contact.create` |
| `update(User, Contact)` | `contact.update` |
| `delete(User, Contact)` | `contact.delete` |

All methods delegate to `$user->hasPermission(...)`.

### Form Requests
| class | authorize() |
|---|---|
| `ContactReadRequest` | `can('view', $this->route('contact'))` for show; `can('viewAny', Contact::class)` for index (same class, route param is null on index) |
| `ContactStoreRequest` | `can('create', Contact::class)` |
| `ContactUpdateRequest` | `can('update', $this->route('contact'))` |
| `ContactDestroyRequest` | `can('delete', $this->route('contact'))` |

`ContactReadRequest::authorize()` branches on whether `$this->route('contact')` is set.

`ContactStoreRequest::rules()`:
- `first_name`: required, string, max:255
- `last_name`: required, string, max:255
- `email`: nullable, email, max:255
- `customer_id`: required, exists:customers,id

`ContactUpdateRequest::rules()`: same fields as store minus `customer_id`, all `sometimes`.

### Controller: `ContactController`
- `index(ContactReadRequest $request)` — all contacts eager-loaded with `customers`, searchable on name/email, paginated 25. Returns `Contacts/IndexPage`.
- `show(ContactReadRequest $request, Contact $contact)` — loads `$contact->customers`. Returns `Contacts/ShowPage`.
- `store(ContactStoreRequest $request)` — creates `Contact`, then `$customer->contacts()->attach($contact->id)`. Redirects to `contacts.index`.
- `update(ContactUpdateRequest $request, Contact $contact)` — updates contact fields only. Redirects back.
- `destroy(ContactDestroyRequest $request, Contact $contact)` — deletes contact (pivot cascades). Redirects back.

### Permissions migration
Seeds: `contact.read`, `contact.create`, `contact.update`, `contact.delete` (same pattern as `2026_06_05_000002_seed_materialusageunit_permissions.php`).

### Routes
```php
Route::resource('contacts', ContactController::class)->except(['create', 'edit']);
```

---

## Frontend

### Menu (`MainLayout.vue`)
`Klanten` entry gains `children` + `open: false`, same pattern as Werkbonnen:
```js
{
    name: 'Klanten',
    href: '/customers',
    icon: UsersIcon,
    current: false,
    requiresPermission: 'customer.read',
    children: [
        { name: 'Contacten', href: '/contacts', icon: UserIcon, current: false, requiresPermission: 'contact.read' },
    ],
    open: false,
}
```

### `Pages/Contacts/IndexPage.vue`
Mirrors `Products/IndexPage.vue` structure:
- `IndexHeaderComponent` with title "Contacten", search, add button (gated on `contact.create`).
- `CreateRecordForm` (external-trigger) with fields: voornaam, achternaam, e-mail, klant (ComboBox of all customers).
- `BoxComponent` with grid list: columns Naam, E-mail, Klant, Acties.
- Each row: full name links to `/contacts/{id}`, email, customer name links to `/customers/{id}`, delete button (gated on `contact.delete`).
- Pagination.

### `Pages/Contacts/ShowPage.vue`
Mirrors `Products/ShowPage.vue` structure:
- Breadcrumb: `Contacten > {full_name}`.
- Header showing full name.
- `BoxComponent` with `EditableTextField` inline editing for `first_name`, `last_name`, `email` (gated on `contact.update`).
- Read-only section listing associated customers (name links to customer show page).
- No separate edit page — all editing inline.

### Customer show page (`Pages/Customers/ShowPage.vue`)
A new "Contacten" section added below the existing contact details block on the main card. Shows a compact list of the customer's contacts (name + email). Each links to `/contacts/{id}`. If `contact.create` permission: inline add form scoped to this customer (no customer picker; `customer_id` passed as hidden field).

---

## Constraints
- `customer_id` is the only contactable type for now; the pivot schema allows future extension.
- The `contactname` field on `Customer` is not removed — it's a legacy free-text field; the new `Contact` model is separate.
- No phone field in MVP — the model only carries first name, last name, email per the spec.
