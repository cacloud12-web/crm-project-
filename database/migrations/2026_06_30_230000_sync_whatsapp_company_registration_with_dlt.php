<?php

use App\Models\MessageTemplate;
use App\Models\SmsTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DLT_BODY = 'Hi {#var#}, please send the required docs for company registration. Need help? Reach us. -{#var#} lawseva';

    private const WA_BODY = 'Hi {{1}}, please send the required docs for company registration. Need help? Reach us. -{{2}} lawseva';

    public function up(): void
    {
        if (! Schema::hasColumn('message_templates', 'meta_api_name')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->string('meta_api_name', 120)->nullable()->after('template_name');
            });
        }

        MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'company_registration_docs',
                'language_code' => 'en',
            ],
            [
                'display_name' => 'Company Registration Docs (DLT)',
                'body_template' => self::WA_BODY,
                'status' => MessageTemplate::STATUS_APPROVED,
                'category' => 'UTILITY',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'static:LawSeva',
                ],
                'meta_components' => [
                    'dlt_template_id' => '1707172327125429758',
                    'sender_id' => 'CACLOD',
                    'linked_sms_template' => 'DLT Template',
                ],
                'is_active' => true,
            ],
        );

        SmsTemplate::query()->updateOrCreate(
            ['dlt_template_id' => '1707172327125429758'],
            [
                'template_name' => 'Company Registration Docs',
                'sender_id' => 'CACLOD',
                'body_template' => self::DLT_BODY,
                'variable_map' => ['ca_name', 'static:LawSeva'],
                'status' => SmsTemplate::STATUS_APPROVED,
                'is_active' => true,
            ],
        );

        SmsTemplate::query()->updateOrCreate(
            ['template_name' => 'CA Cloud Desk OTP'],
            [
                'sender_id' => 'CACLOD',
                'body_template' => 'Your CA Cloud Desk OTP is {#var#}. Keep OTPs secret. Don\'t share with anyone. CA Cloud Desk Team',
                'variable_map' => ['{#var#}' => 'otp'],
                'status' => SmsTemplate::STATUS_APPROVED,
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_templates', 'meta_api_name')) {
            Schema::table('message_templates', function (Blueprint $table) {
                $table->dropColumn('meta_api_name');
            });
        }
    }
};
