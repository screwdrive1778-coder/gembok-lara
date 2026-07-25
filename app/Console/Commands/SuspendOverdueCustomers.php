<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SuspendOverdueCustomers extends Command
{
    protected $signature = 'billing:suspend-overdue {--days=7 : Days after due date} {--dry-run : Preview without making changes}';
    protected $description = 'Suspend customers with overdue invoices';

    protected $mikrotik;
    protected $whatsapp;

    public function __construct(MikrotikService $mikrotik, WhatsAppService $whatsapp)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
        $this->whatsapp = $whatsapp;
    }

    public function handle()
    {
        $days = $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = now()->subDays($days)->toDateString();

        $this->info("Finding customers with invoices overdue since {$cutoffDate}...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find customers with overdue invoices
        $overdueInvoices = Invoice::where('status', 'unpaid')
            ->whereDate('due_date', '<', $cutoffDate)
            ->with(['customer'])
            ->get()
            ->unique('customer_id');

        if ($overdueInvoices->isEmpty()) {
            $this->info('No overdue customers found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$overdueInvoices->count()} customers with overdue invoices.");

        $suspended = 0;
        $notified = 0;
        $errors = 0;

        foreach ($overdueInvoices as $invoice) {
            $customer = $invoice->customer;

            if (!$customer || $customer->status === 'suspended') {
                continue;
            }

            $this->line("Processing: {$customer->name} ({$customer->pppoe_username})");

            if (!$dryRun) {
                try {
                    // Disconnect from Mikrotik
                    if ($customer->pppoe_username && $this->mikrotik->isConnected()) {
                        $this->mikrotik->disconnectPPPoE($customer->pppoe_username);
                    }

                    // Update customer status
                    $customer->update(['status' => 'suspended']);
                    $suspended++;

                    // Send WhatsApp notification
                    if ($customer->phone) {
                        $result = $this->whatsapp->sendSuspensionNotice($customer);
                        if ($result['success']) {
                            $notified++;
                        }
                    }

                    Log::info('Customer suspended', ['customer_id' => $customer->id]);

                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Failed to suspend customer', [
                        'customer_id' => $customer->id,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $suspended++;
            }
        }

        $this->newLine();
        $this->info("Suspension process completed!");
        $this->table(
            ['Action', 'Count'],
            [
                ['Suspended', $suspended],
                ['Notified', $notified],
                ['Errors', $errors],
            ]
        );

        return Command::SUCCESS;
    }
}
