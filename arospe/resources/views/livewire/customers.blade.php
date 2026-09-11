<?php
/**
 * View for App\Livewire\Customers\Index (story 0044). Flat path, not
 * customers/index.blade.php -- Livewire's Finder strips a trailing ".index"
 * segment for an Index component in a subfolder, the same exception
 * App\Livewire\Users\Index / App\Livewire\Roles\Index already rely on; see
 * docs/conventions/naming.md#exception-a-component-named-index-resolves-to-its-parent-folders-name.
 *
 * Structure mirrors resources/views/livewire/users.blade.php /
 * roles.blade.php: header with live count + create action, a four-column
 * table (D-6), one create/edit modal serving both, a delete-confirmation
 * modal, and an explicit empty state. Both modals' inner content is wrapped
 * in its own @if so only one "Cancel" control is ever in the DOM at a time.
 *
 * Four markup rules reused verbatim from users.blade.php / shipping.blade.php
 * (docs/errors-log.md):
 *   1. @js(...) around every wire:click argument.
 *   2. data-test="edit-customer-{id}" / "delete-customer-{id}" on BOTH the
 *      enabled and disabled branch of each row action.
 *   3. A disabled row/header action is a separate @if/@else branch wrapped in
 *      an explicit <flux:tooltip>, never a conditionally-bound :tooltip prop.
 *   4. cursor-not-allowed! lives on that <flux:tooltip> wrapper, never on the
 *      disabled <flux:button> itself (Flux's disabled:pointer-events-none
 *      takes the button out of hit-testing).
 */
?>
<div class="w-full">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
            <flux:subheading>
                {{ $this->customersSummary }}
            </flux:subheading>
        </div>

        @if ($this->canCreateCustomer)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" data-test="create-customer">
                {{ __('New customer') }}
            </flux:button>
        @else
            <flux:tooltip :content="__('customers.index.action_not_allowed')" class="cursor-not-allowed!">
                <flux:button variant="primary" icon="plus" data-test="create-customer" disabled>
                    {{ __('New customer') }}
                </flux:button>
            </flux:tooltip>
        @endif
    </div>

    <div class="mt-6">
        @if (count($this->customers) > 0)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('customers.index.shipping_location') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->customers as $customer)
                        <flux:table.row :key="$customer['id']">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:avatar :name="$customer['name']" size="sm" />

                                    <div class="min-w-0">
                                        <div class="font-medium text-zinc-800 dark:text-white">{{ $customer['name'] }}</div>
                                        <div class="text-zinc-500 dark:text-zinc-400 text-sm truncate">{{ $customer['email'] }}</div>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $customer['phone'] ?? '—' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($customer['shippingCity'] || $customer['shippingCountry'])
                                    {{ collect([$customer['shippingCity'], $customer['shippingCountry']])->filter()->implode(', ') }}
                                @else
                                    —
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    @if ($customer['canEdit'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            aria-label="{{ __('Edit :name', ['name' => $customer['name']]) }}"
                                            data-test="edit-customer-{{ $customer['id'] }}"
                                            wire:click="openEditModal(@js($customer['id']))"
                                            class="cursor-pointer!"
                                        />
                                    @else
                                        <flux:tooltip :content="__('customers.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="pencil-square"
                                                aria-label="{{ __('Edit :name', ['name' => $customer['name']]) }}"
                                                data-test="edit-customer-{{ $customer['id'] }}"
                                                disabled
                                            />
                                        </flux:tooltip>
                                    @endif

                                    @if ($customer['canDelete'])
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label="{{ __('Delete :name', ['name' => $customer['name']]) }}"
                                            data-test="delete-customer-{{ $customer['id'] }}"
                                            wire:click="confirmDelete(@js($customer['id']))"
                                            class="cursor-pointer! text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                        />
                                    @else
                                        <flux:tooltip :content="__('customers.index.action_not_allowed')" class="cursor-not-allowed!">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                aria-label="{{ __('Delete :name', ['name' => $customer['name']]) }}"
                                                data-test="delete-customer-{{ $customer['id'] }}"
                                                disabled
                                                class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                            />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="p-8 text-center border rounded-lg border-zinc-200 dark:border-zinc-700">
                <flux:text>{{ __('customers.index.empty') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Create / edit modal --}}
    <flux:modal name="customer-modal" class="max-w-2xl md:min-w-2xl" @close="closeModal" wire:model="showModal">
        {{-- The inner content only renders while the modal is open (rather than being
        present-but-hidden at all times), so its "Cancel" text never collides with the
        delete-confirmation modal's own "Cancel" button for text-based lookups, and so the
        data-test hook itself is only ever in the DOM when the modal is genuinely open
        (docs/errors-log.md, 2026-09-10 entry). --}}
        @if ($showModal)
            <div class="space-y-6" data-test="customer-modal">
                <flux:heading size="lg">
                    {{ $this->isEditing ? __('Edit customer') : __('Create customer') }}
                </flux:heading>

                <div class="space-y-4">
                    <flux:input wire:model="name" :label="__('Name')" required autofocus />
                    <flux:input wire:model="email" :label="__('Email')" type="email" required />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                </div>

                <div class="space-y-4">
                    <flux:heading size="md">{{ __('customers.form.shipping_heading') }}</flux:heading>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input wire:model="shippingAddressLine1" :label="__('customers.form.address_line1')" />
                        <flux:input wire:model="shippingAddressLine2" :label="__('customers.form.address_line2')" />
                        <flux:input wire:model="shippingCity" :label="__('customers.form.city')" />
                        <flux:input wire:model="shippingPostalCode" :label="__('customers.form.postal_code')" />
                        <flux:input wire:model="shippingProvince" :label="__('customers.form.province')" />
                        <flux:input
                            wire:model="shippingCountry"
                            :label="__('customers.form.country')"
                            :description="__('customers.form.country_hint')"
                            maxlength="2"
                        />
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="md">{{ __('customers.form.billing_heading') }}</flux:heading>

                        <flux:button variant="ghost" size="sm" wire:click="copyShippingToBilling" data-test="same-as-shipping">
                            {{ __('customers.form.same_as_shipping') }}
                        </flux:button>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input wire:model="billingAddressLine1" :label="__('customers.form.address_line1')" />
                        <flux:input wire:model="billingAddressLine2" :label="__('customers.form.address_line2')" />
                        <flux:input wire:model="billingCity" :label="__('customers.form.city')" />
                        <flux:input wire:model="billingPostalCode" :label="__('customers.form.postal_code')" />
                        <flux:input wire:model="billingProvince" :label="__('customers.form.province')" />
                        <flux:input
                            wire:model="billingCountry"
                            :label="__('customers.form.country')"
                            :description="__('customers.form.country_hint')"
                            maxlength="2"
                        />
                    </div>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeModal" data-test="cancel-customer-modal">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="primary"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        data-test="save-customer-button"
                    >
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal name="delete-customer-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
        @if ($showDeleteModal)
            <div class="space-y-6" data-test="delete-customer-modal">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('customers.delete.confirm_title') }}</flux:heading>
                    <flux:text>
                        {{ __('customers.delete.confirm_body', ['name' => $deletingCustomerName]) }}
                    </flux:text>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" wire:click="closeDeleteModal" data-test="cancel-delete-customer-modal">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button
                        variant="danger"
                        wire:click="deleteCustomer"
                        wire:loading.attr="disabled"
                        wire:target="deleteCustomer"
                        data-test="confirm-delete-customer-button"
                    >
                        {{ __('Delete :name', ['name' => $deletingCustomerName]) }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
