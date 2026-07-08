<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CrmDefaultTemplatesSeeder extends Seeder
{
    /**
     * @return list<array{name: string, category: string, subject: string, body: string}>
     */
    private function definitions(): array
    {
        $footer = "\n\n{{COMPANY_NAME}}";
        $greet = "Hello {{CA_NAME}},\n\n";

        return [
            ['Welcome Lead', 'Lead', 'Welcome to {{COMPANY_NAME}}', $greet."Thank you for your interest in {{COMPANY_NAME}}. We are excited to connect with you and {{FIRM_NAME}}.\n\nOur team will reach out shortly to understand your requirements.\n\n{{SUPPORT_PHONE}} | {{SUPPORT_EMAIL}}".$footer],
            ['Lead Assigned', 'Lead', 'Your lead has been assigned', $greet."Your enquiry has been assigned to {{EMPLOYEE_NAME}} ({{EMPLOYEE_ROLE}}).\n\nThey will contact you on {{MOBILE}} or {{EMAIL}} shortly.\n\nThank you.".$footer],
            ['First Follow-up', 'Follow-up', 'Following up on your enquiry', $greet."This is a follow-up regarding your enquiry with {{COMPANY_NAME}}.\n\n📅 Date: {{FOLLOWUP_DATE}}\n🕒 Time: {{FOLLOWUP_TIME}}\n📝 Next action: {{NEXT_ACTION}}\n\n{{REMARKS}}".$footer],
            ['Reminder Follow-up', 'Reminder', 'Reminder: Follow-up scheduled', $greet."Friendly reminder for your scheduled follow-up.\n\n📅 {{FOLLOWUP_DATE}} at {{FOLLOWUP_TIME}}\nType: {{FOLLOWUP_TYPE}}\n\nPlease let us know if you need to reschedule.".$footer],
            ['Demo Scheduled', 'Demo', 'Demo scheduled with {{COMPANY_NAME}}', $greet."Your demo has been successfully scheduled.\n\n📅 Date:\n{{DEMO_DATE}}\n\n🕒 Time:\n{{DEMO_TIME}}\n\n👤 Demo Provider:\n{{DEMO_PROVIDER}}\n\n🔗 Meeting Link:\n{{MEETING_LINK}}\n\nThank you.".$footer],
            ['Demo Reminder', 'Reminder', 'Reminder: Upcoming demo', $greet."Reminder for your upcoming demo.\n\n📅 {{DEMO_DATE}}\n🕒 {{DEMO_TIME}}\n🔗 {{MEETING_LINK}}\n\nWe look forward to meeting you.".$footer],
            ['Demo Rescheduled', 'Demo', 'Demo rescheduled', $greet."Your demo has been rescheduled.\n\n📅 New Date: {{DEMO_DATE}}\n🕒 New Time: {{DEMO_TIME}}\n🔗 {{MEETING_LINK}}\n\nPlease confirm your availability.".$footer],
            ['Demo Completed', 'Demo', 'Thank you for attending the demo', $greet."Thank you for attending the demo with {{DEMO_PROVIDER}}.\n\nWe hope the session was helpful. Our team will share the next steps shortly.\n\n{{REMARKS}}".$footer],
            ['Proposal Shared', 'Sales', 'Proposal from {{COMPANY_NAME}}', $greet."Please find our proposal for {{PLAN_NAME}}.\n\n💰 Amount: {{AMOUNT}}\n📄 Invoice: {{INVOICE_NO}}\n\nLet us know if you have any questions.".$footer],
            ['Details Shared', 'Sales', 'Product details shared', $greet."We have shared the requested details for {{PLAN_NAME}}.\n\nTeam size: {{TEAM_SIZE}}\nWebsite: {{WEBSITE}}\n\nFeel free to reach out for clarification.".$footer],
            ['Negotiation Started', 'Sales', 'Let us discuss your plan', $greet."We would like to discuss pricing and plan options for {{PLAN_NAME}}.\n\nCurrent offer: {{AMOUNT}}\nBalance: {{BALANCE}}\n\nPlease share a convenient time to connect.".$footer],
            ['Payment Reminder', 'Invoice', 'Payment reminder', $greet."This is a reminder for your pending payment.\n\n💰 Amount: {{AMOUNT}}\n📄 Invoice: {{INVOICE_NO}}\nStatus: {{PAYMENT_STATUS}}\n\nPlease complete payment at your earliest convenience.".$footer],
            ['Purchase Confirmation', 'Sales', 'Purchase confirmed', $greet."Your purchase of {{PLAN_NAME}} is confirmed.\n\n📅 Purchase Date: {{PURCHASE_DATE}}\n💰 Amount: {{AMOUNT}}\n\nWelcome aboard!".$footer],
            ['Invoice Sent', 'Invoice', 'Invoice {{INVOICE_NO}}', $greet."Your invoice has been generated.\n\n📄 Invoice No: {{INVOICE_NO}}\n💰 Amount: {{AMOUNT}}\n📅 Date: {{PURCHASE_DATE}}\n\nPayment status: {{PAYMENT_STATUS}}".$footer],
            ['Subscription Activated', 'Sales', 'Subscription activated', $greet."Your subscription for {{PLAN_NAME}} is now active.\n\n📅 Start: {{PURCHASE_DATE}}\n📅 Expiry: {{EXPIRY_DATE}}\n\nThank you for choosing {{COMPANY_NAME}}.".$footer],
            ['Renewal Reminder', 'Renewal', 'Renewal reminder', $greet."Your {{PLAN_NAME}} subscription is due for renewal.\n\n📅 Expiry: {{EXPIRY_DATE}}\n💰 Renewal amount: {{AMOUNT}}\n\nRenew now to avoid interruption.".$footer],
            ['Cooling Period Reminder', 'Reminder', 'Cooling period ending soon', $greet."Your cooling period for {{PLAN_NAME}} ends on {{COOLING_PERIOD}}.\n\nPlease confirm if you wish to continue or make changes before this date.".$footer],
            ['Expiry Reminder', 'Renewal', 'Plan expiring soon', $greet."Your {{PLAN_NAME}} plan expires on {{EXPIRY_DATE}}.\n\nRenew early to continue uninterrupted access to our services.".$footer],
            ['Thank You', 'General', 'Thank you from {{COMPANY_NAME}}', $greet."Thank you for connecting with {{COMPANY_NAME}}. We appreciate your time and trust.\n\n{{EMPLOYEE_NAME}}".$footer],
            ['Feedback Request', 'Support', 'We value your feedback', $greet."We hope you are satisfied with {{COMPANY_NAME}} services.\n\nPlease share your feedback so we can serve you better.\n\n{{SUPPORT_EMAIL}}".$footer],
            ['Trial Started', 'Marketing', 'Your trial has started', $greet."Your trial for {{PLAN_NAME}} has started.\n\n📅 Expiry: {{EXPIRY_DATE}}\nTeam size: {{TEAM_SIZE}}\n\nExplore all features and reach out if you need help.".$footer],
            ['Trial Ending', 'Reminder', 'Trial ending soon', $greet."Your trial for {{PLAN_NAME}} ends on {{EXPIRY_DATE}}.\n\nUpgrade now to keep your data and continue using {{COMPANY_NAME}}.".$footer],
            ['Plan Upgrade', 'Sales', 'Upgrade your plan', $greet."Upgrade to {{PLAN_NAME}} for enhanced features.\n\n💰 Amount: {{AMOUNT}}\n\nContact {{EMPLOYEE_NAME}} to proceed.".$footer],
            ['Support Follow-up', 'Support', 'Support follow-up', $greet."Following up on your support request.\n\n📝 Remarks: {{REMARKS}}\n📅 {{FOLLOWUP_DATE}}\n\n{{SUPPORT_PHONE}} | {{SUPPORT_EMAIL}}".$footer],
            ['Missed Call Follow-up', 'Follow-up', 'We tried reaching you', $greet."We tried calling you on {{MOBILE}} but could not connect.\n\nPlease call us back or share a convenient time.\n\n{{EMPLOYEE_NAME}} — {{EMPLOYEE_PHONE}}".$footer],
            ['Meeting Link Reminder', 'Demo', 'Meeting link reminder', $greet."Here is your meeting link for the upcoming session:\n\n🔗 {{MEETING_LINK}}\n\n📅 {{DEMO_DATE}} at {{DEMO_TIME}}".$footer],
            ['Welcome to Service', 'General', 'Welcome to {{COMPANY_NAME}}', $greet."Welcome to {{COMPANY_NAME}}! We are glad to have {{FIRM_NAME}} on board.\n\nYour account manager: {{EMPLOYEE_NAME}}\n{{SUPPORT_EMAIL}}".$footer],
            ['Account Manager Introduction', 'General', 'Meet your account manager', $greet."I am {{EMPLOYEE_NAME}}, your account manager at {{COMPANY_NAME}}.\n\n📧 {{EMPLOYEE_EMAIL}}\n📞 {{EMPLOYEE_PHONE}}\n\nI will be your primary point of contact.".$footer],
            ['General Announcement', 'Marketing', 'Important update from {{COMPANY_NAME}}', $greet."We have an important announcement for you.\n\n{{REMARKS}}\n\nVisit {{WEBSITE}} for more details.".$footer],
            ['Festival Greeting', 'Marketing', 'Season\'s greetings from {{COMPANY_NAME}}', $greet."Warm wishes from everyone at {{COMPANY_NAME}}!\n\nMay this season bring success and happiness to you and {{FIRM_NAME}}.\n\n{{COMPANY_ADDRESS}}".$footer],
        ];
    }

    public function run(): void
    {
        $header = '{{COMPANY_NAME}}';
        $footer = '— {{COMPANY_NAME}}';

        foreach ($this->definitions() as [$name, $category, $subject, $body]) {
            $slug = Str::slug($name, '_');
            $waName = Str::slug($name, '_');

            EmailTemplate::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'category' => $category,
                    'header' => $header,
                    'subject' => $subject,
                    'body' => $body,
                    'footer' => $footer,
                    'description' => 'Default '.$category.' email template',
                    'is_active' => true,
                    'publish_status' => 'active',
                ],
            );

            MessageTemplate::query()->updateOrCreate(
                [
                    'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                    'template_name' => $waName,
                ],
                [
                    'display_name' => $name,
                    'category' => $category,
                    'header' => $header,
                    'body_template' => $body,
                    'footer' => $footer,
                    'language_code' => 'en',
                    'status' => MessageTemplate::STATUS_APPROVED,
                    'publish_status' => 'active',
                    'is_active' => true,
                ],
            );
        }
    }
}
