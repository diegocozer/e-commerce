<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ManageRules;
use App\Modules\Shipping\Http\Requests\Admin\SaveRuleRequest;
use App\Modules\Shipping\Http\Resources\Admin\RuleResource;
use App\Modules\Shipping\Models\ShippingRule;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ShippingRuleController
{
    public function __construct(private readonly ManageRules $rules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'method_id' => ['sometimes', 'integer'],
            'zone_id' => ['sometimes', 'nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $query = ShippingRule::query()->with('zone')
            ->orderBy('method_id')->orderByRaw('zone_id NULLS FIRST')->orderBy('priority')->orderBy('id');
        if (isset($filters['method_id'])) {
            $query->where('method_id', (int) $filters['method_id']);
        }
        if ($request->has('zone_id')) {
            $zone = $request->query('zone_id');
            $zone === null || $zone === '' || $zone === 'null' ? $query->whereNull('zone_id') : $query->where('zone_id', (int) $zone);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        return RuleResource::collection($query->get());
    }

    public function show(ShippingRule $rule): RuleResource
    {
        return new RuleResource($rule->load('zone'));
    }

    public function store(SaveRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($request->validated(), ActorRef::current());

        return (new RuleResource($rule->load('zone')))->additional(['warnings' => $this->rules->warnings($rule)])->response()->setStatusCode(201);
    }

    public function update(SaveRuleRequest $request, ShippingRule $rule): JsonResponse
    {
        $rule = $this->rules->update($rule, $request->validated(), ActorRef::current());

        return (new RuleResource($rule->load('zone')))->additional(['warnings' => $this->rules->warnings($rule)])->response();
    }

    public function destroy(ShippingRule $rule): Response
    {
        $this->rules->delete($rule, ActorRef::current());

        return response()->noContent();
    }

    public function duplicate(ShippingRule $rule): JsonResponse
    {
        return (new RuleResource($this->rules->duplicate($rule, ActorRef::current())->load('zone')))->response()->setStatusCode(201);
    }

    public function reorder(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'method_id' => ['required', 'integer', Rule::exists('shipping_methods', 'id')],
            'zone_id' => ['present', 'nullable', 'integer'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        return RuleResource::collection(collect($this->rules->reorder((int) $data['method_id'], $data['zone_id'] === null ? null : (int) $data['zone_id'], array_map('intval', $data['ids']), ActorRef::current()))->each->load('zone'));
    }
}
