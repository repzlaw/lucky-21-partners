<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZendeskTicket extends Model
{
    use HasFactory;

    protected $table = 'zendesk_tickets';

    public function items()
    {
        return $this->hasMany(ZendeskTicketItem::class, 'ticketid', 'ticketid');
    }

    public function tags()
    {
        return $this->hasMany(ZendeskTicketTag::class, 'ticketid', 'ticketid');
    }
}
