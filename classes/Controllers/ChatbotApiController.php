<?php
declare(strict_types=1);

namespace Grav\Plugin\AiChatbot\Controllers;

use Grav\Plugin\AiChatbot\AnalyticsReportGenerator;
use Grav\Plugin\AiChatbot\ChatbotHandler;
use Grav\Plugin\AiChatbot\Logger;
use Grav\Plugin\AiChatbot\SecurityGuardrail;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class ChatbotApiController
 * Grav 2.0 REST API for AI Chatbot (replaces legacy /chatbot-api exit() hack).
 *
 * Public (no auth): query, summarize - protected by rate-limit + guardrail.
 * Admin (auth): metrics, logs, security, model tools, index rebuild, export.
 *
 * @license GPL-3.0-or-later
 */
class ChatbotApiController extends AbstractApiController
{
    protected function pluginConfig(): array
    {
        return (array)$this->config->get('plugins.ai-chatbot', []);
    }

    protected function handler(bool $withPages = false): ChatbotHandler
    {
        if ($withPages) {
            try {
                $pages = $this->grav['pages'];
                if (method_exists($pages, 'enablePages')) {
                    $pages->enablePages();
                }
                if (method_exists($pages, 'init')) {
                    $pages->init();
                }
            } catch (\Throwable $t) {
                // Page context is optional; FAQ, contact, and RAG fall back gracefully.
            }
        }
        return new ChatbotHandler($this->grav, $this->pluginConfig());
    }

    /**
     * POST /ai-chatbot/query - public visitor chat.
     */
    public function query(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['question']);

        $response = $this->handler(true)->processRequest([
            'action' => (($body['action'] ?? '') === 'force_ai') ? 'force_ai' : 'query',
            'question' => trim((string)$body['question']),
            'history' => $body['history'] ?? [],
            'current_route' => $body['current_route'] ?? '/',
        ]);

        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * POST /ai-chatbot/summarize - public page summary.
     */
    public function summarize(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $response = $this->handler(true)->processRequest([
            'action' => 'summarize_page',
            'question' => 'Summarize Page',
            'current_route' => $body['current_route'] ?? '/',
        ]);

        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * GET /ai-chatbot/metrics - admin dashboard metrics.
     */
    public function metrics(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');
        $this->denyIfDemo($request);

        $response = $this->handler()->processRequest(['action' => 'get_metrics']);
        unset($response['http_code']);
        return ApiResponse::create($response);
    }

    /**
     * GET /ai-chatbot/logs?per_page= - admin live logs (paginated).
     */
    public function logs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');
        $this->denyIfDemo($request, 'Raw logs are hidden in demo mode.');

        $pagination = $this->getPagination($request, 50);
        $logger = new Logger($this->grav);
        $all = $logger->getLogs();
        $slice = array_slice($all, $pagination['offset'], $pagination['limit']);

        return ApiResponse::paginated(
            $slice,
            count($all),
            $pagination['page'],
            $pagination['limit'],
            $this->getApiBaseUrl() . '/ai-chatbot/logs'
        );
    }

    /**
     * GET /ai-chatbot/security - admin threat audit.
     */
    public function security(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $data = SecurityGuardrail::getSecurityAuditData($this->grav);
        return ApiResponse::create(['status' => 'success', 'data' => $data]);
    }

    /**
     * POST /ai-chatbot/test-key - admin only.
     */
    public function testKey(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $response = $this->handler()->processRequest(array_merge($body, ['action' => 'test_api_key']));
        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * POST /ai-chatbot/models - admin only.
     */
    public function fetchModels(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $response = $this->handler()->processRequest(array_merge($body, ['action' => 'fetch_models']));
        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * POST /ai-chatbot/health - admin only.
     */
    public function testHealth(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $response = $this->handler()->processRequest(array_merge($body, ['action' => 'test_model_health']));
        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * POST /ai-chatbot/reindex - admin only.
     */
    public function rebuildIndex(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $response = $this->handler()->processRequest(['action' => 'rebuild_rag_index']);
        $status = (int)($response['http_code'] ?? 200);
        unset($response['http_code']);
        return ApiResponse::create($response, $status);
    }

    /**
     * POST /ai-chatbot/unlock - admin only, release IP lockouts.
     */
    public function unlock(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        SecurityGuardrail::releaseLockouts();
        return ApiResponse::create(['status' => 'success', 'message' => 'Active IP lockouts released.']);
    }

    /**
     * POST /ai-chatbot/clear-logs - admin only, clear the error log and release IP lockouts.
     */
    public function clearLogs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');
        $this->denyIfDemo($request, 'Logs are hidden in demo mode.');

        $logger = new Logger($this->grav);
        $logger->clearErrorLogs();
        SecurityGuardrail::releaseLockouts();

        return ApiResponse::create([
            'status' => 'success',
            'message' => 'Error logs cleared and active IP lockouts released.',
        ]);
    }

    /**
     * GET /ai-chatbot/export?format=csv|json|raw_interactions - admin only.
     * Returns a download Response instead of exit().
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');
        $this->denyIfDemo($request, 'Exports are hidden in demo mode.');

        $query = $request->getQueryParams();
        $format = strtolower(trim((string)($query['format'] ?? 'csv')));
        if (!in_array($format, ['csv', 'json', 'raw_interactions'], true)) {
            $format = 'csv';
        }

        $generator = new AnalyticsReportGenerator($this->grav);
        $dateStr = date('Y-m-d');

        if ($format === 'raw_interactions') {
            $logger = new Logger($this->grav);
            $payload = json_encode(array_values($logger->getLogs()), JSON_PRETTY_PRINT);
            return new \Grav\Framework\Psr7\Response(200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"ai-chatbot-interactions-{$dateStr}.json\"",
            ], (string)$payload);
        }

        if ($format === 'json') {
            $payload = json_encode($generator->generateJsonReport(), JSON_PRETTY_PRINT);
            return new \Grav\Framework\Psr7\Response(200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"ai-chatbot-analytics-{$dateStr}.json\"",
            ], (string)$payload);
        }

        $csv = $generator->generateCsvReport();
        return new \Grav\Framework\Psr7\Response(200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"ai-chatbot-analytics-{$dateStr}.csv\"",
        ], $csv);
    }
}
