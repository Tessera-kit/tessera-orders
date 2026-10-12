<?php

namespace App\Modules\Orders\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Actions\MarkOrderPaidAction;
use App\Modules\Orders\Actions\VoidOrderAction;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReceiptService;
use App\Modules\Tenant\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function __construct(private ReceiptService $receiptService, private BranchAccess $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Order::query()
            ->where('tenant_id', $user->tenant_id)
            ->with(['cashier:id,name', 'branch:id,name', 'customer:id,name']);

        $this->access->scope($query, $user, $request->input('branch_id'));

        if ($request->filled('search')) {
            $query->where('order_number', 'like', '%'.$request->input('search').'%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('payment_type')) {
            $query->where('payment_type', $request->input('payment_type'));
        }

        if ($request->filled('debt_status')) {
            $query->where('debt_status', $request->input('debt_status'));
        }

        $orders = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => [...$request->only(['search', 'status', 'date_from', 'date_to', 'payment_type', 'debt_status']), 'branch_id' => $this->access->canSeeAll($user) ? $this->access->effective($user, $request->input('branch_id')) : null],
            'statuses' => collect(OrderStatus::cases())->map(fn ($status) => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ]),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $user = $request->user();

        if ($order->tenant_id !== $user->tenant_id) {
            abort(403);
        }

        $this->access->ensure($user, $order->branch_id);

        $order->load(['items.product', 'payment', 'cashier:id,name', 'branch:id,name', 'customer:id,name,phone']);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'receipt' => $this->receiptService->generateReceiptData($order),
            'appliedDiscounts' => $order->discountApplications()
                ->selectRaw('rule_name, coupon_code, SUM(amount) as amount')
                ->groupBy('rule_name', 'coupon_code')
                ->get()
                ->map(fn ($row): array => ['rule_name' => $row->rule_name, 'coupon_code' => $row->coupon_code, 'amount' => (float) $row->amount]),
        ]);
    }

    public function void(Request $request, Order $order)
    {
        $user = $request->user();

        if ($order->tenant_id !== $user->tenant_id) {
            abort(403);
        }

        if (! $user->can('void orders')) {
            abort(403, 'You do not have permission to void orders.');
        }

        $this->access->ensure($user, $order->branch_id);

        try {
            app(VoidOrderAction::class)->execute($order, $user);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order voided successfully.');
    }

    public function markAsPaid(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();

        if ($order->tenant_id !== $user->tenant_id) {
            abort(403);
        }

        $this->access->ensure($user, $order->branch_id);

        try {
            app(MarkOrderPaidAction::class)->execute($order);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order marked as paid.');
    }
}
