<?php

namespace App\Services\Zendesk;

use Exception;
use App\Models\ZendeskTicket;
use App\Models\AdvTicketQuery;
use App\Models\AbInventoryTool;
use App\Models\ZendeskCandidate;
use App\Models\ZendeskTicketTag;
use App\Models\ZendeskTicketItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketAutomationService
{
    /**
     * Process low spend tickets for CSAT automation
     */
    public function csatAutomationLowSpend()
    {
        // Fetch low spend tickets that are older than 5 days and have been solved
        $tickets = ZendeskTicket::query()
            ->where('status', 'solved')
            ->where('status_change_date', '<', now()->subDays(5))
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_item');
            })
            ->whereHas('items', function ($query) {
                $query->where('item_key', 'like', '%Low Spend%')
                    ->whereNotNull('additional_data_json');
            })
            ->with(['items', 'tags'])
            ->get();

        foreach ($tickets as $ticket) {
            // Decode JSON data from the ticket
            $ticketData = json_decode($ticket->ticket_json, true);

            // Skip tickets that already have a satisfaction rating
            if (isset($ticketData['satisfaction_rating']['id'])) {
                continue;
            }

            foreach ($ticket->items as $item) {
                // Decode additional data JSON
                $originalMetrics = json_decode($item->additional_data_json, true);

                // Fetch new spend metrics from the inventory tool
                $newSpend = AbInventoryTool::query()
                    ->selectRaw('COALESCE(SUM(COALESCE(adApiSpSpend, 0)), 0) / 14.0 as newspend')
                    ->where('parenttitle', $originalMetrics['parenttitle'])
                    ->where('storeid', $ticket->storeid)
                    ->first();

                // Skip if no metrics are found
                if (!$newSpend || empty($newSpend->newspend)) {
                    continue;
                }

                // Determine success or failure
                $isSuccess = $newSpend->newspend > ($originalMetrics['Avg_Spend_Last_14_Days'] * 1.14);

                // Generate a comment based on new metrics
                $comment = sprintf(
                    'New Avg Spend = $%.2f; Prior spend was $%.2f',
                    round($newSpend->newspend, 2),
                    round($originalMetrics['Avg_Spend_Last_14_Days'], 2)
                );

                // Handle satisfaction rating and follow-up based on success
                processSatisfactionRating($ticket->ticketid, $originalMetrics['buyer'], $isSuccess, $comment, $ticketData, $ticket->storeid);
            }
        }
    }

    /**
     * Automates CSAT scoring and follow-up for out-of-budget tickets.
     */
    public function csatAutomationOutOfBudget()
    {
        // Fetch out-of-budget tickets
        $tickets = ZendeskTicket::query()
            ->where('status', 'solved')
            ->where('status_change_date', '<', now()->subDays(5))
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_item');
            })
            ->whereHas('items', function ($query) {
                $query->where('item_key', 'like', '%Out Of Budge%')
                    ->whereNotNull('additional_data_json')
                    ->where('parenttitle', '<>', '');
            })
            ->with(['items', 'tags'])
            ->get();

        Log::info("Found {$tickets->count()} tickets to process for out-of-budget automation.");

        foreach ($tickets as $ticket) {
            foreach ($ticket->items as $item) {
                // Decode ticket JSON and additional data JSON
                $ticketData = json_decode($ticket->ticket_json, true);
                $originalMetrics = json_decode($item->additional_data_json, true);

                // Skip tickets that already have a satisfaction rating
                if (isset($ticketData['satisfaction_rating']['id'])) {
                    continue;
                }

                // Fetch new time-in-budget metrics 
                $newTimeInBudget = AbInventoryTool::query()
                    ->selectRaw('COALESCE(MAX(COALESCE(percentTimeInBudget, 0)), 0) as Time_in_Budget')
                    ->where('parenttitle', $originalMetrics['parenttitle'])
                    ->where('storeid', $ticket->storeid)
                    ->first();

                // Skip if no metrics are found
                if (!$newTimeInBudget || empty($newTimeInBudget->Time_in_Budget)) {
                    continue;
                }

                // Calculate old and new "time out of budget" percentages
                $oldTimeOutOfBudget = round(100 - $originalMetrics['Time_in_Budget'], 1);
                $newTimeOutOfBudget = round(100 - $newTimeInBudget->Time_in_Budget, 1);

                // Determine success based on a 17% reduction in out-of-budget time
                $isSuccess = $oldTimeOutOfBudget * 0.83 > $newTimeOutOfBudget;

                // Generate a comment summarizing the results
                $comment = sprintf(
                    'Previous Time Out Of Budget = %.1f%%; Since solved = %.1f%%',
                    $oldTimeOutOfBudget,
                    $newTimeOutOfBudget
                );

                // Handle satisfaction rating and follow-up tickets based on success
                processSatisfactionRating(
                    $ticket->ticketid,
                    $originalMetrics['buyer'],
                    $isSuccess,
                    $comment,
                    $ticketData,
                    $ticket->storeid
                );
            }
        }
    }

    /**
     * Refresh advertising ticket data based on different query types.
     */
    public function refreshAdvTicketData()
    {
        // Loop over different advertising query types defined in AdvTicketQuery
        foreach (AdvTicketQuery::cases() as $key => $qtype) {
            try {
                // Prepare the query and replace semicolons with newline for readability
                $query = str_replace(";", ";\n", $qtype->value);

                // Execute the query and get the candidates
                $candidates = DB::select(DB::raw($query));
                $numCandidates = count($candidates);
                Log::info("Found {$numCandidates} candidates for {$qtype->name}");

                // Skip if no candidates found
                if ($numCandidates == 0) {
                    continue;
                }

                // Delete existing records in the table based on the query type
                ZendeskCandidate::where('QueryType', $qtype->name)->delete();

                $cnt = 0;

                // Iterate through each candidate and process the data
                foreach ($candidates as $candidate) {
                    try {
                        // Get metrics for the candidate
                        $metrics = getMetricsForParent($candidate->ParentTitle, $candidate->storeid, $qtype);

                        // Merge the metrics with the candidate data
                        $candidateData = array_merge((array) $candidate, $metrics);
                        $candidateData['QueryType'] = $qtype->name;

                        // Insert the candidate data into the ZendeskCandidates table
                        ZendeskCandidate::create($candidateData);

                        // Log progress every 50 records
                        if (++$cnt % 50 == 0) {
                            Log::info("Created {$cnt}/{$numCandidates} items for {$qtype->name}");
                        }
                    } catch (Exception $e) {
                        Log::error("Exception creating {$candidate->ParentTitle} for {$qtype->name}", [
                            'exception' => $e,
                        ]);
                    }
                    \Sentry\captureException($e);
                }

                Log::info("Finished adding {$cnt} records for {$qtype->name}");
            } catch (Exception $e) {
                Log::error("Error updating advertising batch query: {$qtype->name}", [
                    'exception' => $e,
                ]);
                \Sentry\captureException($e);
            }
        }
    }

    /**
     * Automate CSAT based on High ACOS ticket criteria.
     */
    public function csatAutomationHighAcos()
    {
        // query to fetch tickets that match 'High ACOS'
        $tickets = ZendeskTicket::with(['items' => function($query) {
            $query->where('item_key', 'like', '%High ACOS%')
                  ->whereNotNull('additional_data_json');
        }, 'tags'])
        ->where('status', 'solved')
        ->whereDate('status_change_date', '<', now()->subDays(5))
        ->get();

        // Log number of records found
        $numRecs = $tickets->count();
        Log::info("Found {$numRecs} records to check for csatautomationhighacos");

        foreach ($tickets as $ticket) {
            $ticketData = json_decode($ticket->ticket_json, true);
            $storeid = $ticket->storeid;

            // Skip ticket if it has already been rated
            if (isset($ticketData['satisfaction_rating']['id'])) {
                continue;
            }

            $originalMetrics = json_decode($ticket->additional_data_json, true);

            // Get metrics from the ab_inventory_tool table
            $new_metrics = AbInventoryTool::selectRaw('coalesce(sum(coalesce(adapispsales,0)), 0) as adapispsales,
                                                      coalesce(sum(coalesce(adapispspend,0)), 0) as adapispspend')
                ->where('storeid', $originalMetrics['storeid'])
                ->where('parenttitle', $ticket->parenttitle)
                ->first();

            if (empty($new_metrics)) {
                continue;
            }

            $oldacos = round($originalMetrics['ACOS'], 0);
            $newacos = $new_metrics->adapispsales == 0 ? 0 : round($new_metrics->adapispspend / $new_metrics->adapispsales * 100.0, 0);

            // Determine success based on sliding scale of original ACOS value
            $isSuccess = determineSuccess($oldacos, $newacos);

            // Comment to be added to the satisfaction rating
            $comment = "Previous ACOS = {$oldacos}%, since solved - {$newacos}%";

            // Create satisfaction rating based on success
            processSatisfactionRating($ticket->ticketid, $originalMetrics['buyer'], $isSuccess, $comment, $ticketData, $ticket->storeid);
        }
    }

    /**
     * Update the inventory tool with advertising ticket data.
     */
    public function updateInvToolWithAdvTickets()
    {
        // Fetch titles that have advertising tickets, excluding those that have 'adv_task_improve_keywords'
        $titles = ZendeskTicket::with(['items', 'tags'])
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_item');
            })
            ->whereDoesntHave('tags', function ($query) {
                $query->where('tag', 'adv_task_improve_keywords');
            })
            ->where('status', '<>', 'closed')
            ->whereHas('items', function ($query) {
                $query->whereNotNull('parenttitle');
            })
            ->get()
            ->groupBy(['items.parenttitle', 'storeid'])
            ->map(function ($group) {
                return [
                    'parenttitle' => $group->first()->items->first()->parenttitle,
                    'storeid' => $group->first()->storeid,
                ];
            });

        // Check if no titles were found
        if ($titles->isEmpty()) {
            Log::warning('Did not find any advertising tickets');
            return;
        }

        // Clear out existing 'zendesk_advert_tickets' field
        AbInventoryTool::query()->update(['zendesk_advert_tickets' => null]);

        // Process each title and update the inventory tool
        foreach ($titles as $title) {
            $tickets = ZendeskTicket::with(['items', 'tags'])
                ->whereHas('tags', function ($query) {
                    $query->where('tag', 'adv_item');
                })
                ->where('status', '<>', 'closed')
                ->whereHas('items', function ($query) use ($title) {
                    $query->where('parenttitle', $title['parenttitle']);
                })
                ->where('storeid', $title['storeid'])
                ->get(['ticketid', 'status', 'storeid', 'subject']);

            $ticketDetails = [];

            // Format ticket details
            foreach ($tickets as $ticket) {
                $ticketType = getTicketTypeFromSubject($ticket->subject);
                $ticketDetails[] = "{$ticket->ticketid}-{$ticketType}-{$ticket->status}";
            }

            // Update the ab_inventory_tool with the formatted ticket details
            AbInventoryTool::where('storeid', $title['storeid'])
                ->where('parenttitle', $title['parenttitle'])
                ->update(['zendesk_advert_tickets' => implode(', ', $ticketDetails)]);
        }

        Log::info('Advertising ticket data updated in inventory tool successfully.');
    }

    /**
     * Update the inventory tool with advertising task improvement keyword ticket data.
     */
    public function updateInvToolWithAdvTkWickets()
    {
        // Fetch titles that have advertising task improvement keyword tickets
        $titles = ZendeskTicket::with(['items', 'tags'])
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_task_improve_keywords');
            })
            ->whereHas('items', function ($query) {
                $query->whereNotNull('parenttitle');
            })
            ->get()
            ->groupBy(['items.parenttitle', 'storeid'])
            ->map(function ($group) {
                return [
                    'parenttitle' => $group->first()->items->first()->parenttitle,
                    'storeid' => $group->first()->storeid,
                ];
            });

        // Check if no titles were found
        if ($titles->isEmpty()) {
            Log::warning('Did not find any advertising task improvement keyword tickets');
            return;
        }

        // Clear out existing 'zendesk_adv_kw_tickets' field
        AbInventoryTool::query()->update(['zendesk_adv_kw_tickets' => null]);

        // Process each title and update the inventory tool
        foreach ($titles as $title) {
            $tickets = ZendeskTicket::with(['items', 'tags'])
                ->whereHas('tags', function ($query) {
                    $query->where('tag', 'adv_task_improve_keywords');
                })
                ->whereHas('items', function ($query) use ($title) {
                    $query->where('parenttitle', $title['parenttitle']);
                })
                ->where('storeid', $title['storeid'])
                ->get()
                ->map(function ($ticket) {
                    return [
                        'ticketid' => $ticket->ticketid,
                        'status' => $ticket->status,
                        'storeid' => $ticket->storeid,
                        'updated_at' => $ticket->updated_at->format('m-d-y'),
                    ];
                });

            $ticketDetails = [];

            // Format ticket details
            foreach ($tickets as $ticket) {
                $ticketDetails[] = "{$ticket['ticketid']} - {$ticket['status']} - {$ticket['updated_at']}";
            }

            // Update the ab_inventory_tool with the formatted ticket details
            AbInventoryTool::where('storeid', $title['storeid'])
                ->where('parenttitle', $title['parenttitle'])
                ->update(['zendesk_adv_kw_tickets' => implode(', ', $ticketDetails)]);
        }

        Log::info('Advertising task improvement keyword ticket data updated in inventory tool successfully.');
    }

    /**
     * Clean up tickets that meet certain criteria.
     */
    public function cleanupTickets()
    {
        // Fetch tickets with empty parenttitle that were created after September 1st, 2023
        $ticketsToDelete = ZendeskTicket::with(['items', 'tags'])
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_item');
            })
            ->whereHas('items', function ($query) {
                $query->where(DB::raw('trim(coalesce(parenttitle,\'\'))'), '');
            })
            ->where('created_at', '>', '2023-09-01')
            ->get();

        // Delete tickets that meet the criteria
        foreach ($ticketsToDelete as $ticket) {
            try {
                // Get Zendesk client instance
                $client = getZendeskClient(); 
                $client->tickets()->delete($ticket->ticketid);

                // Delete associated ticket data from the database
                ZendeskTicket::where('ticketid', $ticket->ticketid)->delete();
                ZendeskTicketItem::where('ticketid', $ticket->ticketid)->delete();
                ZendeskTicketTag::where('ticketid', $ticket->ticketid)->delete();

                Log::info("Deleted ticket ID {$ticket->ticketid} from Zendesk and database.");
            } catch (Exception $e) {
                Log::error("Error deleting ticket ID {$ticket->ticketid}: " . $e->getMessage());
            }
        }

        // Fetch tickets with null storeid created after September 1st, 2023
        $ticketsWithoutStoreId = ZendeskTicket::with(['items', 'tags'])
            ->whereHas('tags', function ($query) {
                $query->where('tag', 'adv_item');
            })
            ->whereNull('storeid')
            ->where('created_at', '>', '2023-09-01')
            ->get()
            ->map(function ($ticket) {
                return [
                    'ticketid' => $ticket->ticketid,
                    'parenttitle' => $ticket->items->first()->parenttitle,
                    'vendorid' => $ticket->items->first()->vendorid,
                ];
            });

        // Update storeid for tickets that have no storeid but can be found in ab_inventory_tool
        foreach ($ticketsWithoutStoreId as $ticket) {
            try {
                $invItem = AbInventoryTool::where('parenttitle', $ticket->parenttitle)
                    ->where('vendorid', $ticket->vendorid)
                    ->first();

                if ($invItem && $invItem->storeid) {
                    ZendeskTicket::where('ticketid', $ticket->ticketid)
                        ->update(['storeid' => $invItem->storeid]);

                    Log::info("Updated storeid for ticket ID {$ticket->ticketid} with storeid {$invItem->storeid}.");
                }
            } catch (Exception $e) {
                Log::error("Error updating storeid for ticket ID {$ticket->ticketid}: " . $e->getMessage());
            }
        }

        Log::info('Ticket cleanup completed.');
    }

}
