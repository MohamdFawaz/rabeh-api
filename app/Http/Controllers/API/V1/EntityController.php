<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\EntityResource;
use App\Models\Entity;

class EntityController extends Controller
{
    use APIController;
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {

    }

    public function index()
    {
        try {
            $entities = Entity::query()->withTranslation()->get(); //todo add pagination
            return $this->respond(EntityResource::collection($entities));
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }

    public function offerBanner()
    {
        try {
            $offer_entities = Entity::query()->withTranslation()->get(); //todo add pagination

            return $this->respond(EntityResource::collection($offer_entities));
        }catch (\Exception $e){
            return $this->respondServerError($e);
        }
    }
}
