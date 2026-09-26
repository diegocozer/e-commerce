<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ManageMethods;
use App\Modules\Shipping\Http\Requests\Admin\SaveMethodRequest;
use App\Modules\Shipping\Http\Resources\Admin\MethodResource;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ShippingMethodController
{
    public function __construct(private readonly ManageMethods $methods) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ShippingMethod::query()->withCount('rules')->orderBy('position')->orderBy('id');
        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        return MethodResource::collection($query->get());
    }

    public function show(ShippingMethod $method): MethodResource
    {
        return new MethodResource($method->loadCount('rules'));
    }

    public function store(SaveMethodRequest $request): JsonResponse
    {
        return (new MethodResource($this->methods->create($request->validated(), ActorRef::current())->loadCount('rules')))->response()->setStatusCode(201);
    }

    public function update(SaveMethodRequest $request, ShippingMethod $method): MethodResource
    {
        return new MethodResource($this->methods->update($method, $request->validated(), ActorRef::current())->loadCount('rules'));
    }

    public function destroy(ShippingMethod $method): Response
    {
        $this->methods->delete($method, ActorRef::current());

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:shipping_methods,id']]);
        $this->methods->reorder(array_map('intval', $data['ids']), ActorRef::current());

        return response()->noContent();
    }
}
