<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('template_name', 'task_customermp2et391nk')
            ->where('language_code', 'en_US')
            ->update([
                'display_name' => 'task_customermp2et391nk',
                'body_template' => <<<'BODY'
Dear Mr. {{1}},
A new task titled "{{2}}" has been created for you on {{3}}.
👨‍💼 Assigned Staff: {{4}}
📅 Expected Completion: {{5}}

You can track the task progress and share required documents via your dashboard.
— CA CloudDesk - Demo Account
BODY,
            ]);
    }

    public function down(): void
    {
        // No rollback — prior body is superseded.
    }
};
