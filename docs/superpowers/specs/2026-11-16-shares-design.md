# Sharing photos from a project with its work orders — design

Date: 2026-11-16. Status: approved in conversation and built (see `docs/superpowers/plans/2026-11-16-shares.md`); calls marked **(my call)** were made while writing this.

## Intent

A photo taken on a project (the situation on site, a drawing, a typeplaatje) is often what the monteur on each work order under that project needs to see. Today it only lives on the project, so people upload it again on every work order. A share button on the project's photo puts it in the internal photos of every work order of that project, including work orders made later.

Sharing is stored in a table of its own that can hold any record shared from any record, so documents or remarks can be shared the same way later.

## Goals

- A share button on each photo in the photo strip, only where sharing has somewhere to go: a project's photos.
- Pressing it again stops sharing (toggle; no separate clear button).
- A shared photo shows at the end of the **internal** photo strip on every work order of the project — the standard view and the monteur view — marked as coming from the project.
- On the work order it is read-only: view and lightbox, no annotate, rename, favourite or delete.
- Share and unshare land on the project's timeline.
- The assistant's photo tool sees shared photos on a work order, like the internal ones.
- Each share carries an access level, so editing from the receiving side can be switched on later without changing the table.

## Non-goals

- Editing a shared photo from the work order. The access level is stored and read, but only `view` is ever written.
- Choosing per work order. A share goes to all work orders of the project.
- Sharing from anything other than a project, or sharing anything other than a photo.
- Shared photos on the work-order PDF or the customer's sign-off overview. They are internal.

## Anti-goals

- A delete on the work order that removes the project's photo. Today a delete removes the image itself, which is why shared photos get no controls there.
- Trusting the browser about where a photo lives. The source of a share is read from the photo's own link.
- Shares left behind when the photo is deleted.

## Approaches

1. **One row per photo, keyed on where it was shared from (chosen).** `shares` holds the shared record (morph) and the record it was shared from (morph). A work order finds its shared photos through its `project_id`. New work orders get them for free; one row regardless of how many work orders.
2. One row per receiving work order. Explicit, but a work order created after the share misses it, and unsharing has to find every copy.
3. A `shared` flag on `imageables`. Cheapest, but image-only, which is what the request asked to avoid.

## Design

- **Table `shares`** (tenant migration): `id`, `shareable` morph (what is shared), `source` morph (where it is shared from), `access` string default `view`, `user_id` (who shared, nullable, null on delete), timestamps. Unique on shareable + source, which also serves lookups by shareable, so only the source gets a separate index. FQCNs in the `_type` columns, as everywhere.
- **`ShareAccess` enum** **(my call)**: ordered levels `view` < `edit` < `manage` (manage includes removing). `allows(ShareAccess $needed)` compares levels. Levels rather than a free list of abilities because every shareable kind maps onto them the same way (photo: annotate/rename = edit, delete = manage; document: rename = edit, delete = manage).
- **`Share` model** with `shareable()` and `source()` morphTo, `access` cast to the enum, and `grants(ShareAccess)`. It extends `Pivot`, so the receiving side loads it as the pivot of what was shared and can ask `grants()` there. Which sources can share lives in one place on the model, `canShareFrom()`: for now, a project.
- **`Shareable` trait** on `Image`: `shares()` morphMany, and deletes its shares when the record is deleted.
- **`ServiceOrder::sharedImages()`**: a `belongsToMany` over `shares` from the work order's `project_id`, restricted to source type Project and shareable type Image, using `Share` as the pivot under the name `share` (so the access level reaches the screen), in the order shared.
- **Sharing**: `POST /images/{image}/share` with `shared` true/false — the desired state, so two open screens can't flip it back. Authorized through `ImagePolicy::share` (the `image.update` permission, same as choosing the main photo) **(my call)**. `ShareRecordAction::execute($shareable, $source, $shared)` sets or removes the row and, only when that changed something, dispatches `ImageShareChanged` on the project (one signal for both directions, with the before and after in `changes()`, like `TaskCheckChanged`). A source that cannot share — a photo on anything but a project, or on nothing — is a `Refusal`. A kind of record without a share signal fails before anything is written.
- **Project page**: images load with `is_shared`, which colours the share button.
- **Photo component**: a share button (desktop hover row and the phone's tap menu) when the component is showing a project's photos and the user may update photos. A new `shared` prop takes photos that are shown after the component's own, read-only, with a small badge.
- **Work order**: show page and monteur view pass `shared_images` to the internal strip; the monteur view's internal count includes them.
- **Manual**: the photos paragraph and the project chapter say what sharing does.

## Left to the builder

Icon choice, badge wording, the exact Dutch on the timeline.
