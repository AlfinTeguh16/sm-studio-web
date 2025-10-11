<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Offering extends Model {
    protected $fillable = [
        'mua_id','name_offer','offer_pictures','makeup_type',
        'collaboration','collaboration_price','add_ons','price','person'
      ];
      protected $casts = [
        'offer_pictures' => 'array',
        'add_ons'        => 'array',
        'price'          => 'float',
        'collaboration_price' => 'float',
      ];
      
 }