<?php

use Huddle\Zendesk\Facades\Zendesk;
use Illuminate\Support\Facades\Log;

    /**
     * Process satisfaction rating and follow-up tickets based on success.
     *
     * @param string $ticketId
     * @param string $buyerEmail
     * @param bool $isSuccess
     * @param string $comment
     * @param array $ticketData
     * @param string $storeId
     */
    function processSatisfactionRating($ticketId, $buyerEmail, $isSuccess, $comment, $ticketData, $storeId)
    {
        $buyerEmail = strtolower(getBuyerEmail($buyerEmail));

        if ($isSuccess) {
            createSatisfactionRating($ticketId, $buyerEmail, 'good', $comment);
        } else {
            createSatisfactionRating($ticketId, $buyerEmail, 'bad', $comment);
            createFollowUpTicketFromBadCsat($ticketData, $storeId, $comment);
        }
    }

    /**
     * Mock: Create a satisfaction rating.
     *
     * @param string $ticketId
     * @param string $buyerEmail
     * @param string $rating
     * @param string $comment
     */
    function createSatisfactionRating($ticketId, $buyerEmail, $rating, $comment)
    {
        // We will implement API call or DB operation to create a CSAT rating
        Log::info("Created $rating satisfaction rating for ticket $ticketId: $comment");
    }

    /**
     * Mock: Create a follow-up ticket for bad CSAT ratings.
     *
     * @param array $ticketData
     * @param string $storeId
     * @param string $comment
     */
    function createFollowUpTicketFromBadCsat($ticketData, $storeId, $comment)
    {
        // we will implement your logic for creating follow-up tickets
        Log::info("Created follow-up ticket for store $storeId due to bad CSAT: $comment");
    }

    /**
     * Mock: Retrieve buyer email.
     *
     * @param string $buyer
     * @return string
     */
    function getBuyerEmail($buyer)
    {
        // Replace with actual logic to fetch buyer email
        return $buyer;
    }

    /**
     * Get metrics for the parent title and store ID.
     *
     * @param string $parentTitle
     * @param string $storeId
     * @param \App\Enums\AdvTicketQuery $qtype
     * @return array
     */
    function getMetricsForParent($parentTitle, $storeId, $qtype)
    {
        // Placeholder for actual logic to fetch metrics
        // For example, a database query or an external API call can be implemented here.
        return [
            'some_metric' => 123, // Replace with actual logic
        ];
    }

    /**
     * Determine the success of the ACOS improvement based on the sliding scale.
     *
     * @param float $oldacos
     * @param float $newacos
     * @return bool
     */
    function determineSuccess($oldacos, $newacos)
    {
        if ($oldacos >= 70) {
            return $oldacos * 0.80 > $newacos;
        } elseif ($oldacos >= 50) {
            return $oldacos * 0.75 > $newacos;
        } elseif ($oldacos >= 40) {
            return $oldacos * 0.875 > $newacos;
        }

        return $oldacos * 0.90 > $newacos;
    }

    /**
     * Extract ticket type from the subject of the ticket.
     *
     * @param string $subject
     * @return string
     */
    function getTicketTypeFromSubject($subject)
    {
        $titleWords = explode("-", $subject);
        $ticketType = $titleWords[0];

        // Handle escalation scenario
        if (starts_with($titleWords[0], 'Escalation')) {
            $ticketType = $titleWords[1];
        }

        return $ticketType;
    }

    /**
     * Get the Zendesk client instance.
     */
    function getZendeskClient()
    {
        return new Zendesk;
    }