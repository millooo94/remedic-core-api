<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EditorialCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EditorialCategoryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['content_type' => ['required', Rule::in(['health_pill', 'news'])]]);

        return response()->json(['data' => EditorialCategory::query()->where('content_type', $data['content_type'])->orderBy('name')->get()->map(fn (EditorialCategory $category) => $this->item($category))]);
    }

    public function store(Request $request)
    {
        return response()->json(['data' => $this->item(EditorialCategory::create($this->validatePayload($request)))], 201);
    }

    public function update(Request $request, EditorialCategory $editorialCategory)
    {
        $data = $this->validatePayload($request);
        abort_unless($data['content_type'] === $editorialCategory->content_type, 422);
        $editorialCategory->update($data);

        return response()->json(['data' => $this->item($editorialCategory)]);
    }

    public function destroy(EditorialCategory $editorialCategory)
    {
        abort_if($editorialCategory->posts()->exists(), 422, 'La categoria è usata da uno o più articoli.');
        $editorialCategory->delete();

        return response()->noContent();
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'content_type' => ['required', Rule::in(['health_pill', 'news'])],
            'name' => ['required', 'string', 'max:255'],
        ]);
    }

    private function item(EditorialCategory $category): array
    {
        return ['id' => $category->id, 'content_type' => $category->content_type, 'name' => $category->name];
    }
}
