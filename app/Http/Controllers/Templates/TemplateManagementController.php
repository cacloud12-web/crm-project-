<?php

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Models\CaMaster;
use App\Services\Email\GoDaddyMailService;
use App\Services\Templates\EmailTemplateManagementService;
use App\Services\Templates\TemplateVariableCatalogService;
use App\Http\Requests\Templates\StoreEmailTemplateRequest;
use App\Http\Requests\Templates\UpdateEmailTemplateRequest;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateManagementController extends Controller
{
    public function __construct(
        private readonly TemplateVariableCatalogService $variableCatalog,
        private readonly EmailTemplateManagementService $emailTemplates,
        private readonly GoDaddyMailService $mailService,
    ) {}

    public function variables(): JsonResponse
    {
        return ApiResponse::success([
            'groups' => $this->variableCatalog->groupedForUi(),
            'categories' => $this->variableCatalog->categories(),
            'publish_statuses' => config('template_variables.publish_statuses', []),
        ], 'Template variables loaded');
    }
}
