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

        $tasks = [
            'csatAutomationLowSpend' => 'Low spend tickets processed successfully.',
            'csatAutomationOutOfBudget' => 'Out of budget tickets processed successfully.',
            'refreshAdvTicketData' => 'Ticket data refreshed successfully.',
            'csatAutomationHighAcos' => 'High ACOS tickets processed successfully.',
            'updateInvToolWithAdvTickets' => 'Inventory tool updated with advertising tickets.',
            'updateInvToolWithAdvtkWickets' => 'Inventory tool updated with advertising wickets.',
        ];

        foreach ($tasks as $method => $successMessage) {
            $this->executeTask($method, $successMessage);
        }

        $this->automationService->cleanupTickets();
        $this->info('Zendesk Batch Processing Completed');
    }

    /**
     * Execute a task and handle exceptions.
     *
     * @param string $method
     * @param string $successMessage
     */
    private function executeTask($method, $successMessage)
    {
        try {
            $this->automationService->$method();
            $this->info($successMessage);
        } catch (\Exception $e) {
            $this->error("Error processing task {$method}: " . $e->getMessage());
            Log::error($e->getMessage());
            \Sentry\captureException($e);
        }
    }
}
