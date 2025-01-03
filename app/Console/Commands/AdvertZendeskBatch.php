<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Zendesk\TicketAutomationService;

class AdvertZendeskBatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zendesk:advert-batch-process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processes Zendesk tickets for advertising automation';

    private $automationService;
    private $storeid;

    public function __construct(TicketAutomationService $automationService)
    {
        parent::__construct();

        $this->automationService = $automationService;
        $this->storeid = config('zendesk.storeid');
    }


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Zendesk Batch Processing Started');

        // Check if the store ID is set and matches the expected value
        if ($this->storeid != "AMAZ" && (!request()->query('storeid') || request()->query('storeid') != "AMAZ")) {
            $this->info('Zendesk Batch Processing Stopped: Store ID not accepted.');
            return;
        }

        try {
            $this->automationService->csatAutomationLowSpend();
            $this->info('Low spend tickets processed successfully.');
        } catch (\Exception $e) {
            $this->error('Error processing low spend tickets: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        try {
            $this->automationService->csatAutomationOutOfBudget();
            $this->info('High ACOS tickets processed successfully.');
        } catch (\Exception $e) {
            $this->error('Error processing high ACOS tickets: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        try {
            $this->automationService->refreshAdvTicketData();
            $this->info('Ticket data refreshed successfully.');
        } catch (\Exception $e) {
            $this->error('Error refreshing ticket data: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        try {
            $this->automationService->csatAutomationHighAcos();
            $this->info('High ACOS tickets processed successfully.');
        } catch (\Exception $e) {
            $this->error('Error processing high ACOS tickets: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        try {
            $this->automationService->updateInvToolWithAdvTickets();
            $this->info('Inventory tool updated with advertising tickets.');
        } catch (\Exception $e) {
            $this->error('Error updating inventory tool with advertising tickets: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        try {
            $this->automationService->updateInvToolWithAdvtkWickets();
            $this->info('Inventory tool updated with advertising wickets.');
        } catch (\Exception $e) {
            $this->error('Error updating inventory tool with advertising wickets: ' . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }

        $this->automationService->cleanupTickets();

        $this->info('Zendesk Batch Processing Completed');
    }
}
