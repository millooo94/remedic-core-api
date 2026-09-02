<?php

namespace App\Http\Requests\Api\V1\Admin\BlogPosts;

use App\Models\BlogPost;
use App\Models\EditorialCategory;
use Illuminate\Validation\Validator;

class UpdateBlogPostRequest extends StoreBlogPostRequest
{
    public function rules(): array
    {
        $postId = (int) $this->route('blog_post')->id;

        return $this->rulesFor($postId);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $post = $this->route('blog_post');
            $contentType = (string) ($this->input('content_type') ?? $post->content_type);
            $categoryId = $this->input('editorial_category_id');
            if ($categoryId !== null && ! EditorialCategory::query()->whereKey($categoryId)->where('content_type', $contentType)->exists()) {
                $validator->errors()->add('editorial_category_id', 'La categoria editoriale non appartiene al tipo contenuto selezionato.');
            }
            $relatedIds = $this->input('related_article_ids', []);
            if (is_array($relatedIds) && $relatedIds !== [] && BlogPost::query()->whereKey($relatedIds)->where('content_type', '!=', $contentType)->exists()) {
                $validator->errors()->add('related_article_ids', 'I contenuti correlati devono appartenere allo stesso tipo editoriale.');
            }
            $this->validateSectionMedia($validator);
        }];
    }
}
