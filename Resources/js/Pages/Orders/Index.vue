<script setup>
import BranchFilter from '@/Components/BranchFilter.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import debounce from 'lodash/debounce';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import Select from '@tessera/tessera-ui/Components/Select.vue';
import { Eye } from 'lucide-vue-next';
import IconButton from '@tessera/tessera-ui/Components/IconButton.vue';
import ViewToggle from '@tessera/tessera-ui/Components/ViewToggle.vue';
import GridCard from '@tessera/tessera-ui/Components/GridCard.vue';
import { useViewMode } from '@tessera/tessera-ui/Composables/useViewMode';
import FilterTabs from '@tessera/tessera-ui/Components/FilterTabs.vue';
import DataTable from '@tessera/tessera-ui/Components/DataTable.vue';
import CardGrid from '@tessera/tessera-ui/Components/CardGrid.vue';
import DatePicker from '@tessera/tessera-ui/Components/DatePicker.vue';

const props = defineProps({
    orders: Object,
    filters: Object,
    statuses: Array,
});

const { t } = useI18n();

const tableColumns = computed(() => [
    { key: 'orderNumber', label: t('pos.orderNumber'), cellClass: 'font-medium text-fg' },
    { key: 'customer', label: t('orders.customer'), cellClass: 'text-muted' },
    { key: 'total', label: t('common.total'), cellClass: 'font-medium text-fg' },
    { key: 'status', label: t('common.status') },
    { key: 'debtStatus', label: t('orders.debtStatus') },
    { key: 'date', label: t('common.date'), cellClass: 'text-muted' },
    { key: 'actions', label: t('common.actions'), align: 'end' },
]);
const { mode: viewMode, setMode: setViewMode } = useViewMode('orders');

const statusTabs = computed(() => [{ value: '', label: t('common.all') }, ...(props.statuses ?? [])]);
const { formatCurrency } = useCurrency();
const page = usePage();
const currency = page.props.currency;

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const branch = ref(props.filters?.branch_id ?? '');
const paymentType = ref(props.filters?.payment_type || '');
const debtStatus = ref(props.filters?.debt_status || '');

const applyFilters = debounce(() => {
    router.get(route('orders.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        payment_type: paymentType.value || undefined,
        debt_status: debtStatus.value || undefined,
        branch_id: branch.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, status, dateFrom, dateTo, paymentType, debtStatus, branch], applyFilters);

const getStatusClass = (status) => {
    switch (status) {
        case 'completed': return 'bg-status-success-wash text-status-success';
        case 'voided': return 'bg-status-danger-wash text-status-danger';
        case 'debt': return 'bg-status-warn-wash text-status-warn';
        default: return 'bg-hover-wash text-fg';
    }
};

const getDebtStatusClass = (debtStatus) => {
    return debtStatus === 'paid'
        ? 'bg-status-success-wash text-status-success'
        : 'bg-status-danger-wash text-status-danger';
};

const markAsPaid = (orderId) => {
    router.post(route('orders.mark-paid', orderId), {}, {
        preserveState: false,
        onSuccess: () => {},
    });
};
</script>

<template>
    <Head :title="t('orders.title')" />

    <SidebarLayout>
        <template #header>
            <h2 class="font-display text-xl font-normal leading-tight text-fg">
                {{ t('orders.title') }}
            </h2>
        </template>

        <div class="mb-4 flex justify-end print:hidden">
            <ExportMenu report="orders" :filters="{ search, status, date_from: dateFrom, date_to: dateTo, payment_type: paymentType, debt_status: debtStatus, branch_id: branch }" />
        </div>

        <!-- Filters -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <input v-model="search" type="text" :placeholder="t('pos.orderNumber')"
                class="block w-full rounded-lg border border-line bg-field p-2.5 text-sm focus:border-gold-on focus:ring-ring-accent" />


            <Select v-model="paymentType"
                class="w-full">
                <option value="">{{ t('common.all') }} {{ t('orders.paymentTypes') }}</option>
                <option value="cash">{{ t('orders.cash') }}</option>
                <option value="debt">{{ t('pos.debt') }}</option>
            </Select>

            <Select v-model="debtStatus"
                class="w-full">
                <option value="">{{ t('common.all') }} {{ t('orders.debtStatus') }}</option>
                <option value="unpaid">{{ t('orders.unpaid') }}</option>
                <option value="paid">{{ t('orders.paid') }}</option>
            </Select>

            <DatePicker v-model="dateFrom" :max="dateTo || null" class="w-full" />

            <DatePicker v-model="dateTo" :min="dateFrom || null" class="w-full" />
            <BranchFilter v-model="branch" />
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <FilterTabs v-model="status" :tabs="statusTabs" />
            <ViewToggle class="sm:ms-auto" :model-value="viewMode" @update:model-value="setViewMode" />
            </div>

        <!-- Orders Table -->
        <DataTable
            v-if="viewMode === 'table'"
            :columns="tableColumns"
            :rows="orders.data"
            :empty-title="t('orders.noOrders')"
            :paginator="orders"
        >
            <template #cell-orderNumber="{ row: order }">
                {{ order.order_number }}
            </template>
            <template #cell-customer="{ row: order }">
                <Link v-if="order.customer" :href="route('customers.show', order.customer.id)"
                    class="text-accent hover:underline">
                    {{ order.customer.name }}
                </Link>
                <span v-else>-</span>
            </template>
            <template #cell-total="{ row: order }">
                {{ formatCurrency(order.total, currency) }}
            </template>
            <template #cell-status="{ row: order }">
                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                    :class="getStatusClass(order.status)">
                    {{ order.status }}
                </span>
            </template>
            <template #cell-debtStatus="{ row: order }">
                <div v-if="order.payment_type === 'debt'" class="flex items-center gap-2">
                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                        :class="getDebtStatusClass(order.debt_status)">
                        {{ order.debt_status === 'paid' ? t('orders.paid') : t('orders.unpaid') }}
                    </span>
                    <button
                        v-if="order.debt_status === 'unpaid'"
                        type="button"
                        @click="markAsPaid(order.id)"
                        class="rounded bg-status-success px-2 py-0.5 text-xs text-surface hover:opacity-90"
                    >
                        {{ t('orders.markPaid') }}
                    </button>
                    <span v-else class="text-xs text-faint">
                        {{ order.debt_paid_at ? new Date(order.debt_paid_at).toLocaleDateString() : '' }}
                    </span>
                </div>
                <span v-else class="text-faint">-</span>
            </template>
            <template #cell-date="{ row: order }">
                {{ new Date(order.created_at).toLocaleString() }}
            </template>
            <template #cell-actions="{ row: order }">
                <IconButton :icon="Eye" :label="t('orders.orderDetails')" :href="route('orders.show', order.id)" />
            </template>
        </DataTable>
        <CardGrid v-else
            :items="orders.data"
            :empty-title="t('orders.noOrders')"
            :paginator="orders"
        >
            <template #item="{ item: order }">
                <GridCard>
                    <template #title>{{ order.order_number }}</template>
                    <template #subtitle>
                        <Link v-if="order.customer" :href="route('customers.show', order.customer.id)" class="hover:underline">{{ order.customer.name }}</Link>
                        <span v-else>-</span>
                    </template>
                    <template #badge>
                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium" :class="getStatusClass(order.status)">{{ order.status }}</span>
                    </template>
                    <template #meta>
                        <div class="font-display text-xl text-fg">{{ formatCurrency(order.total, currency) }}</div>
                        <div class="text-muted">{{ new Date(order.created_at).toLocaleString() }}</div>
                        <div v-if="order.payment_type === 'debt'" class="mt-2 flex items-center gap-2">
                            <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium" :class="getDebtStatusClass(order.debt_status)">
                                {{ order.debt_status === 'paid' ? t('orders.paid') : t('orders.unpaid') }}
                            </span>
                            <button
                                v-if="order.debt_status === 'unpaid'"
                                type="button"
                                @click="markAsPaid(order.id)"
                                class="rounded bg-status-success px-2 py-0.5 text-xs text-surface hover:opacity-90"
                            >
                                {{ t('orders.markPaid') }}
                            </button>
                        </div>
                    </template>
                    <template #actions>
                        <IconButton :icon="Eye" :label="t('orders.orderDetails')" :href="route('orders.show', order.id)" />
                    </template>
                </GridCard>
            </template>
        </CardGrid>
    </SidebarLayout>
</template>
