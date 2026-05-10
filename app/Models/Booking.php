<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'hall_id', 'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'booking_date', 'slot', 'guests', 'status',
    ];

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
