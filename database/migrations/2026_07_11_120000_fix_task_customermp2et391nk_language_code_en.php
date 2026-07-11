<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where(function ($query) {
                $query->where('meta_api_name', 'task_customermp2et391nk')
                    ->orWhere('template_name', 'task_customermp2et391nk');
            })
            ->where('language_code', 'en_US')
            ->update(['language_code' => 'en']);
    }

    public function down(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where(function ($query) {
                $query->where('meta_api_name', 'task_customermp2et391nk')
                    ->orWhere('template_name', 'task_customermp2et391nk');
            })
            ->where('language_code', 'en')
            ->where('template_name', 'task_customermp2et391nk')
            ->update(['language_code' => 'en_US']);
    }
};
