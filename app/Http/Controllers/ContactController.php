<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactDestroyRequest;
use App\Http\Requests\ContactReadRequest;
use App\Http\Requests\ContactStoreRequest;
use App\Http\Requests\ContactUpdateRequest;
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
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->appends(['search' => $search]);

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
        $customer_id = $data['customer_id'];
        unset($data['customer_id']);

        $contact = Contact::create($data);
        $contact->customers()->attach($customer_id);

        return redirect()->back()->with('success', 'Contact aangemaakt.');
    }

    public function update(ContactUpdateRequest $request, Contact $contact)
    {
        $data = $request->validated();

        if (array_key_exists('customer_id', $data)) {
            $contact->customers()->sync(array_filter([$data['customer_id']]));
            unset($data['customer_id']);
        }

        $contact->update($data);

        return redirect()->back()->with('success', 'Contact bijgewerkt.');
    }

    public function destroy(ContactDestroyRequest $request, Contact $contact)
    {
        $contact->delete();

        return redirect()->back()->with('success', 'Contact verwijderd.');
    }
}
