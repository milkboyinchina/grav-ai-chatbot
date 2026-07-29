<?php
namespace Grav\Plugin\AiChatbot;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Class ContactPageResolver
 * Resolves contact information from public (/contact) and hidden (/hidden-contacts) pages.
 *
 * @license GPL-3.0-or-later
 */
class ContactPageResolver
{
    protected Grav $grav;
    protected string $publicRoute;
    protected string $hiddenRoute;
    protected bool $enableHidden;

    public function __construct(Grav $grav, $publicRoute = '/contact', string $hiddenRoute = '/hidden-contacts', bool $enableHidden = true)
    {
        $this->grav = $grav;
        if (is_array($publicRoute)) {
            $this->publicRoute = $publicRoute['contact_route'] ?? '/contact';
            $this->hiddenRoute = $publicRoute['hidden_contact_route'] ?? '/hidden-contacts';
            $this->enableHidden = !empty($publicRoute['enable_hidden_contacts']);
        } else {
            $this->publicRoute = $publicRoute ?: '/contact';
            $this->hiddenRoute = $hiddenRoute ?: '/hidden-contacts';
            $this->enableHidden = $enableHidden;
        }
    }

    /**
     * Resolves contact intent if user asks for contact info.
     *
     * @param string $userQuestion
     * @return array|null Returns ['answer' => string] or null if not a contact intent
     */
    public function resolveContactIntent(string $userQuestion): ?array
    {
        $questionLower = strtolower($userQuestion);
        $isContactIntent = preg_match('/(contact|reach|email|phone|address|location|call|support|talk to|office|hours)/i', $questionLower);
        
        if (!$isContactIntent) {
            return null;
        }

        $info = $this->getContactInformation($userQuestion);
        if (empty($info)) {
            return null;
        }

        return ['answer' => $info];
    }

    /**
     * Alias method for resolving contact details.
     */
    public function resolveContactDetails(string $userQuestion): string
    {
        return $this->getContactInformation($userQuestion);
    }

    /**
     * Determines whether user prompt asks for specialized/engineer contact vs standard contact,
     * and extracts appropriate page content.
     *
     * @param string $userQuestion
     * @return string
     */
    public function getContactInformation(string $userQuestion): string
    {
        $questionLower = strtolower($userQuestion);
        $isSpecialized = preg_match('/(engineer|tech|support|devops|lead|direct|internal|emergency)/i', $questionLower);

        $contactText = "";

        // If user asks for specialized contact and hidden pages are enabled
        if ($isSpecialized && $this->enableHidden) {
            $hiddenContent = $this->fetchPageText($this->hiddenRoute, true);
            if (!empty($hiddenContent)) {
                $contactText .= "### Specialized & Engineering Contact Information:\n" . $hiddenContent . "\n\n";
            }
        }

        // Always include public contact info as base
        $publicContent = $this->fetchPageText($this->publicRoute, false);
        if (!empty($publicContent)) {
            $contactText .= "### Public General Contact Information:\n" . $publicContent;
        }

        if (empty($contactText)) {
            return "Please visit our website contact page at " . $this->publicRoute . " or email support.";
        }

        return trim($contactText);
    }

    /**
     * Fetch plain text content from target route.
     */
    protected function fetchPageText(string $route, bool $allowHidden = false): string
    {
        $pagesContainer = $this->grav['pages'] ?? null;
        if (!$pagesContainer) {
            return '';
        }

        try {
            if (method_exists($pagesContainer, 'init')) {
                try {
                    $pagesContainer->init();
                } catch (\Throwable $t) {}
            }
            /** @var Page|null $page */
            $page = $pagesContainer->find($route);
        } catch (\Throwable $e) {
            $page = null;
        }

        if (!$page instanceof Page || !$page->exists()) {
            return '';
        }

        // If page is hidden/unpublished and allowHidden is false, ignore it
        if (!$allowHidden && (!$page->published() || !$page->routable())) {
            return '';
        }

        $content = strip_tags($page->content());
        // Clean up whitespace
        return trim(preg_replace('/\n\s*\n/', "\n", $content));
    }
}
