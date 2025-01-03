<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZendeskTicketTag extends Model
{
    use HasFactory;

    protected $table = 'zendesk_ticket_tags';

    public function ticket()
    {
        return $this->belongsTo(ZendeskTicket::class, 'ticketid', 'ticketid');
    }
}
