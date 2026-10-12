<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useCurrency } from '@/Composables/useCurrency';
import SidebarLayout from '@/Layouts/SidebarLayout.vue';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import { useConfirm } from '@tessera/tessera-ui/Composables/useConfirm';

const props = defineProps({
    order: Object,
    receipt: Object,
    appliedDiscounts: { type: Array, default: () => [] },
});

const { t } = useI18n();
const confirm = useConfirm();
const { formatCurrency } = useCurrency();
const page = usePage();
const currency = page.props.currency;

const getStatusClass = (status) => {
    switch (status) {
        case 'completed':
            return 'bg-status-success-wash text-status-success';
        case 'voided':
            return 'bg-status-danger-wash text-status-danger';
        default:
            return 'bg-hover-wash text-fg';
    }
};

const voidOrder = async () => {
    if (await confirm({ variant: 'danger', message: t('orders.confirmVoid'), confirmText: t('orders.voidOrder') })) {
        router.post(route('orders.void', props.order.id));
    }
};

const printReceipt = () => {
    window.print();
};
</script>

<template>
    <Head :title="`${t('orders.orderDetails')} - ${order.order_number}`" />

    <SidebarLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('orders.index')" class="text-muted hover:text-fg">
                    <ArrowLeft class="h-6 w-6 rtl:rotate-180" />
                </Link>
                <h2 class="font-display text-xl font-normal leading-tight text-fg">
                    {{ t('orders.orderDetails') }}
                </h2>
            </div>
        </template>

        <div class="flex justify-end gap-4 mb-4">
            <button @click="printReceipt"
                class="inline-flex items-center gap-2 rounded-2xl border border-line bg-surface px-4 py-2 text-sm font-medium text-muted hover:bg-hover-wash">
                <Printer class="h-5 w-5" />
                {{ t('orders.printReceipt') }}
            </button>
            <button v-if="order.status === 'completed'" @click="voidOrder"
                class="inline-flex items-center gap-2 rounded-lg bg-danger px-4 py-2 text-sm font-medium text-paper hover:opacity-90">
                {{ t('orders.voidOrder') }}
            </button>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Order Info -->
            <div class="lg:col-span-2">
                <div class="rounded-2xl bg-surface p-6 border border-line">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="font-display text-lg font-normal text-fg">
                                {{ order.order_number }}
                            </h3>
                            <p class="text-sm text-muted">
                                {{ new Date(order.created_at).toLocaleString() }}
                            </p>
                        </div>
                        <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium"
                            :class="getStatusClass(order.status)">
                            {{ t(`orders.${order.status}`) }}
                        </span>
                    </div>

                    <!-- Items -->
                    <div class="mb-6">
                        <h4 class="mb-4 font-medium text-fg">{{ t('orders.items') }}</h4>
                        <div class="space-y-3">
                            <div v-for="item in order.items" :key="item.id"
                                class="flex items-center justify-between rounded-lg border p-3">
                                <div class="flex items-center gap-3">
                                    <img :src="item.product?.image_url" :alt="item.product?.name"
                                        class="h-12 w-12 rounded-lg object-cover" />
                                    <div>
                                        <p class="font-medium text-fg">
                                            {{ item.product?.name }}
                                        </p>
                                        <p class="text-sm text-muted">
                                            {{ formatCurrency(item.unit_price, currency) }} x {{ item.quantity }}
                                        </p>
                                    </div>
                                </div>
                                <p class="font-medium text-fg">
                                    {{ formatCurrency(item.total, currency) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="space-y-2 border-t pt-4">
                        <div class="flex justify-between text-muted">
                            <span>{{ t('common.subtotal') }}</span>
                            <span>{{ formatCurrency(order.subtotal, currency) }}</span>
                        </div>
                        <div v-for="applied in appliedDiscounts" :key="applied.rule_name + applied.coupon_code"
                            class="flex justify-between text-sm text-status-success">
                            <span>{{ applied.rule_name }}<span v-if="applied.coupon_code" class="text-muted"> ({{ applied.coupon_code }})</span></span>
                            <span>-{{ formatCurrency(applied.amount, currency) }}</span>
                        </div>
                        <div v-if="order.discount_amount > 0"
                            class="flex justify-between text-status-success">
                            <span>{{ t('orders.discount') }}</span>
                            <span>-{{ formatCurrency(order.discount_amount, currency) }}</span>
                        </div>
                        <div class="flex justify-between text-lg font-bold text-fg">
                            <span>{{ t('common.total') }}</span>
                            <span>{{ formatCurrency(order.total, currency) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Payment Info -->
                <div class="rounded-2xl bg-surface p-6 border border-line">
                    <h4 class="mb-4 font-medium text-fg">{{ t('orders.paymentMethod') }}</h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-muted">{{ t('orders.cash') }}</span>
                            <span
                                class="text-fg">{{ formatCurrency(order.payment?.cash_received, currency) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted">{{ t('orders.change') }}</span>
                            <span
                                class="text-fg">{{ formatCurrency(order.payment?.change_amount, currency) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Cashier Info -->
                <div class="rounded-2xl bg-surface p-6 border border-line">
                    <h4 class="mb-4 font-medium text-fg">{{ t('orders.cashier') }}</h4>
                    <p class="text-fg">{{ order.cashier?.name }}</p>
                    <p class="text-sm text-muted">{{ order.branch?.name }}</p>
                </div>
            </div>
        </div>
    </SidebarLayout>
</template>
