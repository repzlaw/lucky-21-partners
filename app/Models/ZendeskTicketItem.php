<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZendeskTicketItem extends Model
{
    use HasFactory;

    protected $table = 'zendesk_ticket_items';

    public function ticket()
    {
        return $this->belongsTo(ZendeskTicket::class, 'ticketid', 'ticketid');
    }
}
