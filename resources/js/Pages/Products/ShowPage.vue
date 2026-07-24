<template>
    <BreadcrumbComponent :items="[{ label: 'Producten', href: '/products' }, { label: `${product.brand.name} ${product.model}` }]" />
    <div class="flex flex-col sm:flex-row mt-6 mb-4">
        <BoxComponent class="w-full h-auto sm:w-70 sm:min-h-70 mr-8 flex items-center justify-around"
            v-if="product.main_image?.[0]">
            <img :src="`/storage/${product.main_image[0].path}`" alt="Productafbeelding"
                class="object-cover rounded w-full">
        </BoxComponent>
        <div class="flex flex-col justify-around flex-grow items-start pt-2 sm:py-10 gap-3">
            <div class="flex-col sm:flex-row flex items-start sm:items-center">
                <div class="flex mb-2 sm:mb-0">
                    <span class="text-2xl font-bold mr-2">
                        {{ product.brand.name }}</span>
                    <EditableTextField v-model="form.model" type="input" :decoration="false" :error="form.errors.model"
                        @revert="form.clearErrors('model')">
                        <template #display>
                            <span class="text-2xl font-bold">
                                {{ form.model }}
                            </span>
                        </template>
                    </EditableTextField>
                </div>
                <div class="flex gap-2">
                    <BadgeComponent :color="product.active ? 'green' : 'red'">
                        {{ product.active ? 'Actief' : 'Inactief' }}
                    </BadgeComponent>
                    <BadgeComponent class="block sm:hidden" color="blue" :hasDot="false">{{ product.product_type.name }}
                    </BadgeComponent>
                </div>
            </div>
            <div class="hidden sm:block">
                <BadgeComponent color="blue" :hasDot="false">{{ product.product_type.name }}
                </BadgeComponent>
            </div>
            <div class="flex gap-0 sm:gap-15 flex-wrap">
                <TitleValueIconComponent class="w-1/2 sm:w-auto" :icon="HashIcon" title="Artikelnummer"
                    :value="product.part_no || '—'" />
                <TitleValueIconComponent class="w-1/2 sm:w-auto" :icon="FingerPrintIcon" title="Merk"
                    :value="product.brand.name" />
                <TitleValueIconComponent class="w-1/2 sm:w-auto" :icon="EuroIcon" title="Verkoopprijs"
                    :value="product.retail_price ? `${Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(product.retail_price)}` : '—'" />
                <TitleValueIconComponent class="w-1/2 sm:w-auto" :icon="ClockIcon" title="Aangemaakt op"
                    :value="nlDate(product.created_at)" />
                <TitleValueIconComponent class="w-1/2 sm:w-auto" :icon="CalendarIcon" title="Laatst bijgewerkt op"
                    :value="nlDate(product.updated_at)" />
                <TitleValueIconComponent v-if="product.start_sell" class="w-1/2 sm:w-auto" :icon="CalendarArrowUpIcon"
                    title="Verkocht sinds" :value="nlDate(product.start_sell)" />
                <TitleValueIconComponent v-if="product.end_sell" class="w-1/2 sm:w-auto" :icon="CalendarArrowDownIcon"
                    title="Einde verkoop" :value="nlDate(product.end_sell)" />
            </div>
        </div>
    </div>
    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(chapter, index) in chapters" :key="index" :index="index">
                {{ chapter }}
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <!-- Chapter 0: Productinformatie -->
            <template #chapter-0>
                <TwoThirdsOneThird>
                    <template #main>
                        <BoxComponent>
                            <SectionHeader :icon="CubeIcon" title="Productinformatie"
                                subtitle="Type, garantie en prijsstelling van dit product." chapter="details" />
                            <div class="grid grid-cols-1 md:grid-cols-2">
                                <!-- Left column -->
                                <div class="flex flex-col gap-6 md:pr-8">
                                    <EditableTextField v-model="form.description" type="textarea" label="Beschrijving"
                                        :error="form.errors.description" @revert="form.clearErrors('description')" />
                                    <EditableTextField type="combobox" v-model="form.product_type_id"
                                        :options="productTypes" label="Producttype" :error="form.errors.product_type_id"
                                        @revert="form.clearErrors('product_type_id')">
                                        <template #display>{{productTypes.find(t => t.id === form.product_type_id)?.name
                                            ??
                                            product.product_type.name}}</template>
                                    </EditableTextField>
                                    <EditableTextField v-model="form.warranty" type="input" label="Garantie"
                                        :error="form.errors.warranty" @revert="form.clearErrors('warranty')" />
                                    <div v-if="hasPermission('product.view_prices')">
                                        <EditableTextField v-model="form.retail_price" type="input" inputType="currency"
                                            label="Verkoopprijs" :error="form.errors.retail_price"
                                            @revert="form.clearErrors('retail_price')" />
                                    </div>
                                    <div>
                                        <h3 class="text-xs font-semibold mb-1 text-slate-500">Bundel</h3>
                                        <div class="flex items-center gap-3">
                                            <SwitchComponent v-model="form.bundle" />
                                            <span class="text-sm text-gray-600 dark:text-slate-400">
                                                <template v-if="form.bundle">Dit is een gebundeld product (geen
                                                    serienummer vereist)</template>
                                                <template v-else>Normaal product</template>
                                            </span>
                                        </div>
                                        <p v-if="form.errors.bundle" class="text-sm text-red-600 mt-1">{{
                                            form.errors.bundle }}</p>
                                    </div>
                                </div>
                                <!-- Right column -->
                                <div class="flex flex-col gap-6 md:pl-8 md:border-l md:border-gray-200/70">
                                    <EditableTextField v-model="form.typical_certificate_days" type="input"
                                        label="Typische certificeringstermijn (dagen)"
                                        :error="form.errors.typical_certificate_days"
                                        @revert="form.clearErrors('typical_certificate_days')">
                                        <template #label-suffix>
                                            <InformationCircleIcon
                                                class="inline h-4 w-4 text-gray-400 ml-1 cursor-pointer" v-tooltip="{
                                                    html: true,
                                                    content: `<span class='block w-80'>Het standaard aantal dagen waarmee de keuringsdatum van een machine vooruitgeschoven wordt bij een succesvolle keuring. Laat dit leeg om de termijn van het type (${product.product_type.name}, ${product.product_type.typical_certificate_days ?? 0} dagen) te gebruiken. Als je hier iets invult overschrijft dat de waarde die op het type is ingesteld.</span>`
                                                }" />
                                        </template>
                                    </EditableTextField>
                                    <EditableTextField v-model="form.part_no" type="input" label="Artikelnummer"
                                        :error="form.errors.part_no" @revert="form.clearErrors('part_no')" />
                                    <div v-if="hasPermission('product.view_prices')">
                                        <EditableTextField v-model="form.purchase_price" type="input"
                                            inputType="currency" label="Inkoopprijs" :error="form.errors.purchase_price"
                                            @revert="form.clearErrors('purchase_price')" />
                                    </div>
                                    <div>
                                        <h3 class="text-xs font-semibold mb-1 text-slate-500">Actief</h3>
                                        <div class="flex items-center gap-3">
                                            <SwitchComponent v-model="form.active" />
                                            <span class="text-sm text-gray-600 dark:text-slate-400">
                                                <template v-if="form.active">Product is actief</template>
                                                <template v-else>Product is inactief</template>
                                            </span>
                                        </div>
                                        <p v-if="form.errors.active" class="text-sm text-red-600 mt-1">{{
                                            form.errors.active }}</p>
                                    </div>
                                </div>
                            </div>
                            <CustomFieldsComponent v-if="customFields.length" model-type="product"
                                :model-id="product.id" :custom-fields="customFields"
                                :can-edit="hasPermission('customfield.update')" class="mt-6" />
                            <!-- Attribute values -->
                            <div v-if="productAttributes.length" class="mt-6 border-t border-gray-100 pt-4">
                                <SectionHeader :icon="TagIcon" title="Kenmerken"
                                    subtitle="Eigenschappen waarop dit product te onderscheiden is." chapter="attributes" />
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div v-for="attr in productAttributes" :key="attr.id">
                                        <EditableTextField type="combobox"
                                            :model-value="selectedValues[attr.id] ?? null" :label="attr.name"
                                            :options="attr.values.map(v => ({ id: v.id, name: v.value }))"
                                            @update:model-value="(val) => setAttributeValue(attr.id, val)">
                                            <template #display>
                                                {{attr.values.find(v => v.id === selectedValues[attr.id])?.value ??
                                                    '—'}}
                                            </template>
                                        </EditableTextField>
                                    </div>
                                </div>
                            </div>
                        </BoxComponent>
                    </template>
                    <template #sidebar>
                        <BoxComponent class="mt-4 md:mt-0">
                            <ImageUploadComponent :existing="product.images" :imageable-id="product.id"
                                imageable-type="\App\Models\Product" />
                            <button v-if="hasPermission('image.upload')" @click="openGoogleImagesDialog"
                                class="mt-2 w-full flex items-center justify-center gap-2 text-sm text-blue-600 border border-blue-300 rounded-md px-3 py-2 hover:bg-blue-50">
                                <MagnifyingGlassIcon class="h-4 w-4" />
                                Zoek op Google Afbeeldingen
                            </button>
                        </BoxComponent>
                    </template>
                </TwoThirdsOneThird>
            </template>

            <!-- Chapter 1: Machines -->
            <template #chapter-1>
                <div>
                    <BoxComponent v-if="hasPermission('asset.read') || hasPermission('asset.create')">
                        <SectionHeader :icon="PuzzlePieceIcon" title="Machines"
                            subtitle="De machines die op dit product gebaseerd zijn." chapter="assets" border>
                            <template v-if="hasPermission('asset.create')" #actions>
                                <button @click="addAssetDrawerOpen = true"
                                    class="text-blue-600 hover:text-blue-800 cursor-pointer"
                                    v-tooltip="'Nieuwe machine toevoegen'">
                                    <PlusIcon class="size-4" />
                                </button>
                            </template>
                        </SectionHeader>
                        <AssetListComponent v-if="product.assets.length" :assets="product.assets" class="mt-2" />
                        <p v-else class="text-sm text-gray-400 italic mt-3">Geen machines gekoppeld.</p>
                    </BoxComponent>
                </div>
            </template>

            <!-- Chapter 2: Gerelateerde producten -->
            <template #chapter-2>
                <div>
                    <BoxComponent v-if="hasPermission('productable.read')">
                        <SectionHeader :icon="LinkIcon" title="Gerelateerde producten"
                            subtitle="Onderdelen en toebehoren die bij dit product horen." chapter="products" border>
                            <template v-if="hasPermission('productable.create') && eligibleChildProducts.length"
                                #actions>
                                <button @click="addingRelation = !addingRelation"
                                    class="text-blue-600 hover:text-blue-800 cursor-pointer"
                                    v-tooltip="'Gerelateerd product toevoegen'">
                                    <PlusIcon class="size-4" />
                                </button>
                            </template>
                        </SectionHeader>
                        <!-- Add form -->
                        <div v-auto-animate>
                            <div v-if="addingRelation"
                                class="mt-3 mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
                                <div class="flex gap-2 flex-wrap">
                                    <div class="flex-1 min-w-40">
                                        <label class="block text-xs text-gray-500 mb-1">Gerelateerd product</label>
                                        <ComboBox :options="eligibleChildProducts"
                                            v-model="newRelation.child_product_id" placeholder="Selecteer product" />
                                    </div>
                                    <div class="flex-1 min-w-32">
                                        <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                                        <ComboBox :options="productRelations" v-model="newRelation.product_relation_id"
                                            placeholder="Selecteer type" />
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-xs text-gray-500 mb-1">Aantal</label>
                                        <input type="number" min="1" v-model.number="newRelation.quantity"
                                            class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1.5 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" />
                                    </div>
                                    <div class="flex flex-col">
                                        <label class="block text-xs text-gray-500 mb-1">Verplicht</label>
                                        <SwitchComponent v-model="newRelation.is_required" />
                                    </div>
                                </div>
                                <div class="flex gap-2 justify-end">
                                    <button @click="addingRelation = false"
                                        class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                        Annuleren
                                    </button>
                                    <button @click="submitNewRelation"
                                        class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                        Opslaan
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Existing relations -->
                        <div v-if="childProducts.length === 0 && !addingRelation"
                            class="text-sm text-gray-400 italic mt-3">
                            Geen gerelateerde producten.
                        </div>
                        <table v-if="childProducts.length" class="w-full text-sm mt-3">
                            <thead>
                                <tr class="text-xs text-gray-400 border-b">
                                    <th class="text-left py-1 font-medium">Product</th>
                                    <th class="text-left py-1 font-medium">Type</th>
                                    <th class="text-left py-1 font-medium">Aantal</th>
                                    <th class="text-center py-1 font-medium">Verplicht</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="rel in childProducts" :key="rel.productable_id">
                                    <tr v-if="editingId === rel.productable_id"
                                        class="border-b border-gray-100 bg-gray-50 dark:bg-slate-800">
                                        <td class="py-1.5 pr-2">{{ rel.name }}</td>
                                        <td class="py-1.5 pr-2">
                                            <ComboBox :options="productRelations" v-model="editForm.product_relation_id"
                                                placeholder="Selecteer type" />
                                        </td>
                                        <td class="py-1.5 pr-2">
                                            <input type="number" min="1" v-model.number="editForm.quantity"
                                                class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm" />
                                        </td>
                                        <td class="py-1.5 text-center">
                                            <SwitchComponent v-model="editForm.is_required" />
                                        </td>
                                        <td class="py-1.5 text-right">
                                            <div class="flex justify-end gap-1">
                                                <button @click="cancelEdit"
                                                    class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">Annuleren</button>
                                                <button @click="saveEdit"
                                                    class="px-2 py-0.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Opslaan</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-else class="border-b border-gray-100">
                                        <td class="py-1.5">{{ rel.name }}</td>
                                        <td class="py-1.5 text-gray-500">
                                            {{productRelations.find(r => r.id === rel.product_relation_id)?.name ??
                                                '—'}}
                                        </td>
                                        <td class="py-1.5">{{ rel.quantity }}</td>
                                        <td class="py-1.5 text-center">
                                            <span v-if="rel.is_required" class="text-green-600 text-xs">✓</span>
                                            <span v-else class="text-gray-300 text-xs">—</span>
                                        </td>
                                        <td class="py-1.5 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button v-if="hasPermission('productable.update')"
                                                    @click="startEdit(rel)" class="text-gray-400 hover:text-gray-600">
                                                    <PencilIcon class="size-4" />
                                                </button>
                                                <button v-if="hasPermission('productable.delete')"
                                                    @click="removeRelation(rel.productable_id)"
                                                    class="text-red-400 hover:text-red-600">
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <p v-if="eligibleChildProducts.length === 0 && !childProducts.length"
                            class="text-xs text-gray-400 mt-1">
                            Dit producttype heeft geen subtypen, dus er kunnen geen gerelateerde producten worden
                            toegevoegd.
                        </p>
                        <!-- Parent products -->
                        <div v-if="parentProducts.length" class="mt-4 border-t border-gray-100 pt-3">
                            <p class="text-xs font-medium text-gray-400 mb-2">Onderdeel van</p>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-gray-400 border-b">
                                        <th class="text-left py-1 font-medium">Product</th>
                                        <th class="text-left py-1 font-medium">Type</th>
                                        <th class="text-left py-1 font-medium">Aantal</th>
                                        <th class="text-center py-1 font-medium">Verplicht</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="rel in parentProducts" :key="rel.productable_id"
                                        class="border-b border-gray-100">
                                        <td class="py-1.5">
                                            <Link :href="`/products/${rel.product_id}`" class="text-blue-500 underline">
                                                {{ rel.name }}
                                            </Link>
                                        </td>
                                        <td class="py-1.5 text-gray-500">
                                            {{productRelations.find(r => r.id === rel.product_relation_id)?.name ??
                                                '—'}}
                                        </td>
                                        <td class="py-1.5">{{ rel.quantity }}</td>
                                        <td class="py-1.5 text-center">
                                            <span v-if="rel.is_required" class="text-green-600 text-xs">✓</span>
                                            <span v-else class="text-gray-300 text-xs">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </BoxComponent>
                </div>
            </template>

            <!-- Chapter 3: Documenten -->
            <template #chapter-3>
                <DocumentUploadComponent :existing="product.documents" :documentable-id="product.id"
                    documentable-type="\App\Models\Product" />
            </template>

            <!-- Chapter 4: Leveranciers -->
            <template #chapter-4>
                <div>
                    <BoxComponent>
                        <SectionHeader :icon="BuildingOfficeIcon" title="Leveranciers"
                            subtitle="Bij wie dit product ingekocht kan worden." chapter="suppliers" border>
                            <template v-if="hasPermission('product.update')" #actions>
                                <button @click="addingSupplier = !addingSupplier"
                                    class="text-blue-600 hover:text-blue-800 cursor-pointer"
                                    v-tooltip="'Leverancier koppelen'">
                                    <PlusIcon class="size-4" />
                                </button>
                            </template>
                        </SectionHeader>

                        <!-- Add form -->
                        <div v-auto-animate>
                            <div v-if="addingSupplier"
                                class="mt-3 mb-3 p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2">
                                <div class="flex gap-2 flex-wrap">
                                    <div class="flex-1 min-w-40">
                                        <label class="block text-xs text-gray-500 mb-1">Leverancier</label>
                                        <ComboBox :options="supplierOptions" v-model="newSupplierLink.supplier_id"
                                            placeholder="Selecteer leverancier"
                                            :has-external-searching="suppliersUseAjax" :searching="supplierSearching"
                                            @change="searchSuppliers" />
                                    </div>
                                    <div class="flex-1 min-w-32">
                                        <label class="block text-xs text-gray-500 mb-1">Artikelnummer</label>
                                        <input type="text" v-model="newSupplierLink.article_number"
                                            class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1.5 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                                            placeholder="Optioneel" />
                                    </div>
                                    <div class="flex flex-col">
                                        <label class="block text-xs text-gray-500 mb-1">Voorkeur</label>
                                        <SwitchComponent v-model="newSupplierLink.is_preferred" />
                                    </div>
                                </div>
                                <div class="flex gap-2 justify-end">
                                    <button @click="addingSupplier = false"
                                        class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                        Annuleren
                                    </button>
                                    <button @click="submitNewSupplier" :disabled="!newSupplierLink.supplier_id"
                                        class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700 disabled:opacity-50">
                                        Opslaan
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p v-if="!productSuppliers.length && !addingSupplier" class="text-sm text-gray-400 italic mt-3">
                            Geen leveranciers gekoppeld.
                        </p>

                        <table v-if="productSuppliers.length" class="w-full text-sm mt-3">
                            <thead>
                                <tr class="text-xs text-gray-400 border-b">
                                    <th class="text-left py-1 font-medium">Leverancier</th>
                                    <th class="text-left py-1 font-medium">Artikelnummer</th>
                                    <th class="text-center py-1 font-medium">Voorkeur</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="s in productSuppliers" :key="s.id">
                                    <tr v-if="editingSupplierId === s.id"
                                        class="border-b border-gray-100 bg-gray-50 dark:bg-slate-800">
                                        <td class="py-1.5 pr-2">{{ s.name }}</td>
                                        <td class="py-1.5 pr-2">
                                            <input type="text" v-model="editSupplierForm.article_number"
                                                class="block w-full border-0 rounded-md bg-white dark:bg-slate-900 py-1 pl-2 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                                placeholder="Artikelnummer" />
                                        </td>
                                        <td class="py-1.5 text-center">
                                            <SwitchComponent v-model="editSupplierForm.is_preferred" />
                                        </td>
                                        <td class="py-1.5 text-right">
                                            <div class="flex justify-end gap-1">
                                                <button @click="cancelEditSupplier"
                                                    class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">Annuleren</button>
                                                <button @click="saveEditSupplier(s.id)"
                                                    class="px-2 py-0.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">Opslaan</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-else class="border-b border-gray-100 dark:border-slate-800">
                                        <td class="py-1.5">
                                            <Link :href="`/suppliers/${s.id}`" class="text-blue-500 hover:underline">
                                                {{ s.name }}
                                            </Link>
                                        </td>
                                        <td class="py-1.5 text-gray-500">{{ s.article_number || '—' }}</td>
                                        <td class="py-1.5 text-center">
                                            <span v-if="s.is_preferred" class="text-green-600 text-xs">✓</span>
                                            <span v-else class="text-gray-300 text-xs">—</span>
                                        </td>
                                        <td class="py-1.5 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button v-if="hasPermission('product.update')"
                                                    @click="startEditSupplier(s)"
                                                    class="text-gray-400 hover:text-gray-600">
                                                    <PencilIcon class="size-4" />
                                                </button>
                                                <button v-if="hasPermission('product.update')"
                                                    @click="removeSupplier(s.id)"
                                                    class="text-red-400 hover:text-red-600">
                                                    <TrashIcon class="size-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </BoxComponent>
                </div>
            </template>
        </ChapterContents>
    </ChaptersComponent>

    <!-- Google Images dialog -->
    <div v-if="googleDialog.open" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50"
        @click.self="googleDialog.open = false">
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold">Afbeelding via Google importeren</h2>
                <button @click="googleDialog.open = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Google Afbeeldingen wordt geopend in een nieuw tabblad. Klik met de rechtermuisknop op een afbeelding en
                kies
                <strong>"Afbeeldingsadres kopiëren"</strong> (URL) of <strong>"Afbeelding kopiëren"</strong> (plak
                direct
                hieronder met Ctrl+V).
            </p>
            <a :href="googleDialog.searchUrl" target="_blank" rel="noopener noreferrer"
                class="inline-flex items-center gap-2 text-sm text-blue-600 underline mb-4">
                <MagnifyingGlassIcon class="h-4 w-4" />
                Open Google Afbeeldingen: {{ product.brand.name }} {{ product.model }}
            </a>
            <div class="mt-2">
                <label class="block text-xs text-gray-500 mb-1">Afbeeldings-URL of geplakte afbeelding</label>
                <div class="relative">
                    <input v-model="googleDialog.url" type="text"
                        placeholder="https://... of Ctrl+V om een gekopieerde afbeelding te plakken"
                        class="w-full rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 pr-10"
                        @paste.prevent="handleImagePaste" />
                    <span v-if="googleDialog.pastedPreview"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-green-500 text-xs font-semibold">✓
                        afbeelding</span>
                </div>
                <div v-if="googleDialog.pastedPreview" class="mt-2">
                    <img :src="googleDialog.pastedPreview" class="max-h-20 rounded border border-gray-200"
                        alt="Voorbeeld" />
                </div>
            </div>
            <div v-if="googleDialog.error" class="text-red-500 text-xs mt-2">{{ googleDialog.error }}</div>
            <div class="flex justify-end gap-2 mt-4">
                <button @click="googleDialog.open = false"
                    class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">Annuleren</button>
                <button @click="importGoogleImage" :disabled="!googleDialog.url || googleDialog.importing"
                    class="px-3 py-1.5 text-sm bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-50">
                    {{ googleDialog.importing ? 'Bezig…' : 'Importeren' }}
                </button>
            </div>
        </div>
    </div>

    <!-- Add asset drawer -->
    <DrawerComponent v-model="addAssetDrawerOpen" :title="`Voeg een ${product.brand.name} ${product.model} toe`">
        <AddAssetForm :allCustomers="allCustomers" :customersUseAjax="customersUseAjax" :productId="product.id"
            :isBundle="product.bundle" :productTypicalDays="product.typical_certificate_days"
            :productTypeTypicalDays="product.product_type?.typical_certificate_days"
            :required-productables-by-product="requiredProductablesByProduct" :bare="true"
            @created="addAssetDrawerOpen = false" />
    </DrawerComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue';
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue';
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue';
import ChapterContents from '@/Components/Chapters/ChapterContents.vue';
import { CubeIcon, PuzzlePieceIcon, InformationCircleIcon, LinkIcon, TrashIcon, PlusIcon, PencilIcon, MagnifyingGlassIcon, FingerPrintIcon, BuildingOfficeIcon, TagIcon } from '@heroicons/vue/24/outline';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import { ref, reactive, watch } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import { useComboSearch } from '@/Composables/useComboSearch';
import axios from 'axios';
import ComboBox from '@/Components/UI/ComboBox.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import AddAssetForm from '@/Components/AddAssetForm.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import { hasPermission, nlDate } from '@/Utilities/Utilities';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue';
import TitleValueIconComponent from '@/Components/UI/TitleValueIconComponent.vue';
import { CalendarArrowDownIcon, CalendarArrowUpIcon, CalendarIcon, ClockIcon, EuroIcon, HashIcon } from '@lucide/vue';

const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    allCustomers: {
        type: Array,
        required: true
    },
    customersUseAjax: { type: Boolean, default: false },
    customFields: {
        type: Array,
        default: () => [],
    },
    productTypes: { type: Array, default: () => [] },
    productRelations: { type: Array, default: () => [] },
    eligibleChildProducts: { type: Array, default: () => [] },
    childProducts: { type: Array, default: () => [] },
    parentProducts: { type: Array, default: () => [] },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
    productAttributes: { type: Array, default: () => [] },
    selectedAttributeValues: { type: Object, default: () => ({}) },
    productSuppliers: { type: Array, default: () => [] },
    allSuppliers: { type: Array, default: () => [] },
    suppliersUseAjax: { type: Boolean, default: false },
});

const { options: supplierOptions, searching: supplierSearching, search: searchSuppliers } =
    useComboSearch('suppliers', props.allSuppliers, props.suppliersUseAjax)

const form = useForm({
    description: props.product.description,
    id: props.product.id,
    model: props.product.model,
    brand_id: props.product.brand.id,
    product_type_id: props.product.product_type.id,
    start_sell: props.product.start_sell,
    end_sell: props.product.end_sell,
    typical_certificate_days: props.product.typical_certificate_days,
    retail_price: props.product.retail_price,
    purchase_price: props.product.purchase_price,
    part_no: props.product.part_no,
    bundle: props.product.bundle ?? false,
    active: props.product.active ?? true,
    warranty: props.product.warranty,
});

const chapters = [
    'Overzicht',
    `Machines (${props.product.assets.length})`,
    `Gerelateerde producten (${props.product.child_products.length})`,
    `Documenten (${props.product.documents.length})`,
    `Leveranciers (${props.productSuppliers.length})`,
]
watch([
    () => form.model,
    () => form.description,
    () => form.typical_certificate_days,
    () => form.retail_price,
    () => form.purchase_price,
    () => form.part_no,
    () => form.product_type_id,
    () => form.bundle,
    () => form.active,
    () => form.warranty,
], () => {
    form.patch(`/products/${props.product.id}`, { preserveScroll: true });
});

const addAssetDrawerOpen = ref(false);

const selectedValues = reactive({ ...props.selectedAttributeValues })

function setAttributeValue(attribute_id, value_id) {
    selectedValues[attribute_id] = value_id
    router.post('/productattributevalueables', {
        product_id: props.product.id,
        product_attribute_id: attribute_id,
        product_attribute_value_id: value_id,
    }, { preserveScroll: true })
}

const addingRelation = ref(false)
const newRelation = reactive({
    child_product_id: null,
    product_relation_id: null,
    quantity: 1,
    is_required: false,
})

function submitNewRelation() {
    router.post('/productables', {
        product_id: props.product.id,
        child_product_id: newRelation.child_product_id,
        product_relation_id: newRelation.product_relation_id,
        quantity: newRelation.quantity,
        is_required: newRelation.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingRelation.value = false
            newRelation.child_product_id = null
            newRelation.product_relation_id = null
            newRelation.quantity = 1
            newRelation.is_required = false
        },
    })
}

function removeRelation(productableId) {
    router.delete(`/productables/${productableId}`, { preserveScroll: true })
}

const editingId = ref(null)
const editForm = reactive({ product_relation_id: null, quantity: 1, is_required: false })

function startEdit(rel) {
    editingId.value = rel.productable_id
    editForm.product_relation_id = rel.product_relation_id
    editForm.quantity = rel.quantity
    editForm.is_required = rel.is_required
}

function cancelEdit() {
    editingId.value = null
}

function saveEdit() {
    router.patch(`/productables/${editingId.value}`, {
        product_relation_id: editForm.product_relation_id,
        quantity: editForm.quantity,
        is_required: editForm.is_required,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null },
    })
}

const addingSupplier = ref(false)
const newSupplierLink = reactive({ supplier_id: null, article_number: '', is_preferred: false })

function submitNewSupplier() {
    router.post(`/products/${props.product.id}/suppliers`, {
        supplier_id: newSupplierLink.supplier_id,
        article_number: newSupplierLink.article_number || null,
        is_preferred: newSupplierLink.is_preferred,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingSupplier.value = false
            newSupplierLink.supplier_id = null
            newSupplierLink.article_number = ''
            newSupplierLink.is_preferred = false
        },
    })
}

function removeSupplier(supplier_id) {
    router.delete(`/products/${props.product.id}/suppliers/${supplier_id}`, { preserveScroll: true })
}

const editingSupplierId = ref(null)
const editSupplierForm = reactive({ article_number: '', is_preferred: false })

function startEditSupplier(s) {
    editingSupplierId.value = s.id
    editSupplierForm.article_number = s.article_number || ''
    editSupplierForm.is_preferred = s.is_preferred
}

function cancelEditSupplier() { editingSupplierId.value = null }

function saveEditSupplier(supplier_id) {
    router.patch(`/products/${props.product.id}/suppliers/${supplier_id}`, {
        article_number: editSupplierForm.article_number || null,
        is_preferred: editSupplierForm.is_preferred,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingSupplierId.value = null },
    })
}

const googleDialog = reactive({
    open: false,
    url: '',
    error: null,
    importing: false,
    searchUrl: '',
    pastedPreview: null,
})

function openGoogleImagesDialog() {
    const query = encodeURIComponent(`${props.product.brand.name} ${props.product.model}`)
    googleDialog.searchUrl = `https://www.google.com/search?q=${query}&tbm=isch`
    googleDialog.open = true
    googleDialog.url = ''
    googleDialog.error = null
    googleDialog.importing = false
    googleDialog.pastedPreview = null
}

function handleImagePaste(event) {
    const items = event.clipboardData?.items ?? []
    for (const item of items) {
        if (item.type.startsWith('image/')) {
            const file = item.getAsFile()
            const reader = new FileReader()
            reader.onload = (e) => {
                googleDialog.url = e.target.result
                googleDialog.pastedPreview = e.target.result
            }
            reader.readAsDataURL(file)
            return
        }
    }
    // Fallback: plain text URL pasted
    const text = event.clipboardData?.getData('text') ?? ''
    googleDialog.url = text
    googleDialog.pastedPreview = null
}

async function importGoogleImage() {
    googleDialog.importing = true
    googleDialog.error = null

    try {
        await axios.post('/images/import-from-url', {
            url: googleDialog.url,
            imageable_id: props.product.id,
            imageable_type: '\\App\\Models\\Product',
            name: `${props.product.brand.name} ${props.product.model}`,
        })
        googleDialog.open = false
        router.reload({ preserveScroll: true })
    } catch (e) {
        googleDialog.importing = false
        googleDialog.error = e.response?.data?.message ?? 'De afbeelding kon niet worden geïmporteerd.'
    }
}

</script>
