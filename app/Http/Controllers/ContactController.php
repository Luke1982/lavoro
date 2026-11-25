<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Customers\ContactLinkedToCustomer;
use App\Domain\Signals\Customers\ContactUnlinkedFromCustomer;
use App\Domain\Signals\Signals;
use App\Http\Requests\ContactDestroyRequest;
use App\Http\Requests\ContactReadRequest;
use App\Http\Requests\ContactStoreRequest;
use App\Http\Requests\ContactUpdateRequest;
use App\Models\AssistantFilter;
use App\Models\Contact;
use App\Models\Customer;

class ContactController extends Controller
{
    public function index(ContactReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $contacts = Contact::with('customers')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            )
            )
            ->tap(AssistantFilter::from($request))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->appends(['search' => $search])
            ->withQueryString();

        $customer_count = Customer::count();

        return inertia('Contacts/IndexPage', [
            'contacts' => $contacts,
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'search' => $search,
        ]);
    }

    public function show(ContactReadRequest $request, Contact $contact)
    {
        $contact->load('customers');

        $customer_count = Customer::count();

        return inertia('Contacts/ShowPage', [
            'contact' => $contact,
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
        ]);
    }

    public function store(ContactStoreRequest $request)
    {
        $data = $request->validated();
        $customer = Customer::findOrFail($data['customer_id']);
        unset($data['customer_id']);

        Contact::createFor($customer, $data);

        return redirect()->back()->with('success', 'Contact aangemaakt.');
    }

    public function update(ContactUpdateRequest $request, Contact $contact)
    {
        $data = $request->validated();

        if (array_key_exists('customer_id', $data)) {
            $this->linkCustomers($contact, array_filter([$data['customer_id']]));
            unset($data['customer_id']);
        }

        $contact->update($data);

        return redirect()->back()->with('success', 'Contact bijgewerkt.');
    }

    /**
     * The link is a pivot row, which tells nobody it changed; each customer it
     * reaches or leaves hears it here.
     *
     * @param  array<int, int|string>  $customer_ids
     */
    private function linkCustomers(Contact $contact, array $customer_ids): void
    {
        $changes = $contact->customers()->sync($customer_ids);
        $contact->unsetRelation('customers');

        foreach (Customer::whereKey($changes['detached'])->get() as $customer) {
            Signals::dispatch(new ContactUnlinkedFromCustomer($customer, $contact));
        }

        foreach (Customer::whereKey($changes['attached'])->get() as $customer) {
            Signals::dispatch(new ContactLinkedToCustomer($customer, $contact));
        }
    }

    public function destroy(ContactDestroyRequest $request, Contact $contact)
    {
        $contact->delete();

        return redirect()->back()->with('success', 'Contact verwijderd.');
    }
}
