<?php

declare(strict_types=1);

namespace AIHub\WordPress\Integration;

use AIHub\WordPress\Settings;

/**
 * Injects analytics, session replay, and feedback widgets on the public site.
 */
class InstrumentationManager
{
    private Settings $settings;

    private ConsentGate $consentGate;

    private bool $gtmNoscriptPrinted = false;

    private bool $dataLayerBootstrapped = false;

    private const DEFAULT_MASK_SELECTORS = [
        'input[type="password"]',
        'input[data-sensitive]',
        '.ssn',
        '[data-aihub-mask]',
    ];

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->consentGate = new ConsentGate($settings);
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'injectHead'], 5);
        add_action('wp_body_open', [$this, 'injectBodyOpen']);
        add_action('wp_footer', [$this, 'injectFooter'], 20);
    }

    public function injectHead(): void
    {
        if (!$this->shouldHandleRequest()) {
            return;
        }

        $this->renderDataLayerBootstrap();
        $this->renderAnalyticsScripts();
        $this->renderConsentBridge();
        $this->renderSessionReplaySnippet();
    }

    public function injectBodyOpen(): void
    {
        if (!$this->shouldHandleRequest()) {
            return;
        }

        $this->renderGtmNoscript();
    }

    public function injectFooter(): void
    {
        if (!$this->shouldHandleRequest()) {
            return;
        }

        $this->renderTrackingShim();
        $this->renderFeedbackWidget();
        if (!$this->dataLayerBootstrapped) {
            $this->renderDataLayerBootstrap();
        }

        if (!$this->gtmNoscriptPrinted) {
            $this->renderGtmNoscript();
        }
    }

    private function shouldHandleRequest(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        return $this->hasIntegrationsConfigured();
    }

    private function hasIntegrationsConfigured(): bool
    {
        return $this->hasAnalyticsConfigured()
            || $this->settings->isSessionReplayEnabled()
            || $this->settings->isFeedbackWidgetEnabled();
    }

    private function renderDataLayerBootstrap(): void
    {
        if ($this->dataLayerBootstrapped) {
            return;
        }

        $payload = [
            'event' => 'aiHub.pageContext',
            'page' => $this->buildPageContext(),
            'site' => $this->buildSiteContext(),
            'user' => $this->buildUserContext(),
            'consent' => [
                'analytics' => $this->consentGate->analyticsAllowed() ? 'granted' : 'denied',
                'sessionReplay' => $this->consentGate->sessionReplayAllowed() ? 'granted' : 'denied',
                'feedback' => $this->consentGate->feedbackAllowed() ? 'granted' : 'denied',
            ],
        ];

        $json = wp_json_encode($payload);
        echo "\n<!-- AI Hub data layer bootstrap -->\n";
        printf(
            '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push(%1$s);</script>' . "\n",
            $json ?: '{}'
        );

        $this->dataLayerBootstrapped = true;
    }

    private function hasAnalyticsConfigured(): bool
    {
        return $this->settings->isMatomoEnabled()
            || (bool) ($this->settings->getGtmContainerId() || $this->settings->getGaMeasurementId());
    }

    private function renderAnalyticsScripts(): void
    {
        if (!$this->hasAnalyticsConfigured() || !$this->consentGate->analyticsAllowed()) {
            return;
        }

        if ($this->settings->isMatomoEnabled()) {
            $this->renderMatomoTracker();
        }

        $gtm = $this->settings->getGtmContainerId();
        if ($gtm) {
            $this->renderGtmScript($gtm);
        } else {
            $measurementId = $this->settings->getGaMeasurementId();
            if ($measurementId) {
                $this->renderGaScript($measurementId);
            }
        }
    }

    private function renderMatomoTracker(): void
    {
        $baseUrl = $this->settings->getMatomoUrl();
        $siteId = $this->settings->getMatomoSiteId();

        if (!$baseUrl || !$siteId) {
            return;
        }

        $trackerBase = rtrim($baseUrl, '/') . '/';
        $heatmapLine = $this->settings->isMatomoHeatmapEnabled()
            ? "_paq.push(['HeatmapSessionRecording::enable']);"
            : '';

        echo "\n<!-- Matomo Analytics -->\n";
        printf(
            '<script>var _paq=window._paq=window._paq||[];_paq.push([\'trackPageView\']);_paq.push([\'enableLinkTracking\']);%1$s(function(){var u=\'%2$s\';_paq.push([\'setTrackerUrl\',u+\'matomo.php\']);_paq.push([\'setSiteId\',\'%3$s\']);var d=document,g=d.createElement(\'script\'),s=d.getElementsByTagName(\'script\')[0];g.async=true;g.src=u+\'matomo.js\';s.parentNode.insertBefore(g,s);})();</script>' . "\n",
            $heatmapLine,
            esc_js($trackerBase),
            esc_js($siteId)
        );
    }

    private function renderConsentBridge(): void
    {
        if (!$this->hasAnalyticsConfigured()) {
            return;
        }

        $analytics = $this->consentGate->analyticsAllowed() ? 'granted' : 'denied';
        echo "\n<!-- AI Hub Consent Bridge -->\n";
        printf(
            '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:"gtm.consentUpdate",analytics_storage:"%1$s"});' .
            '(function(w){function update(state){w.dataLayer=w.dataLayer||[];w.dataLayer.push(Object.assign({event:"gtm.consentUpdate"},state||{}));}' .
            'w.addEventListener("ai-hub:consent-update",function(evt){if(evt && evt.detail){update(evt.detail);}});' .
            'if(typeof w.__tcfapi==="function"){try{w.__tcfapi("getTCData",2,function(tcData){if(!tcData){return;}var granted=tcData.purpose&&tcData.purpose.consents&&tcData.purpose.consents[1];' .
            'update({analytics_storage:granted?"granted":"denied"});});}catch(e){}}})(window);</script>' . "\n",
            esc_js($analytics)
        );
    }

    private function renderGtmScript(string $containerId): void
    {
        $container = esc_js($containerId);

        echo "\n<!-- AI Hub Google Tag Manager -->\n";
        printf(
            '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});' .
            'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;' .
            'j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);' .
            '})(window,document,"script","dataLayer","%1$s");</script>' . "\n",
            $container
        );
    }

    private function renderGaScript(string $measurementId): void
    {
        $idForAttr = esc_attr($measurementId);
        $idForJs = esc_js($measurementId);

        echo "\n<!-- AI Hub GA4 -->\n";
        printf(
            '<script async src="https://www.googletagmanager.com/gtag/js?id=%1$s"></script>' . "\n",
            $idForAttr
        );
        printf(
            '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            . 'gtag("js",new Date());gtag("config","%1$s",{"send_page_view":true});</script>' . "\n",
            $idForJs
        );
    }

    private function renderGtmNoscript(): void
    {
        if ($this->gtmNoscriptPrinted) {
            return;
        }

        if (!$this->hasAnalyticsConfigured() || !$this->consentGate->analyticsAllowed()) {
            return;
        }

        $gtm = $this->settings->getGtmContainerId();
        if (!$gtm) {
            return;
        }

        $this->gtmNoscriptPrinted = true;
        $iframeSrc = esc_url(sprintf('https://www.googletagmanager.com/ns.html?id=%s', rawurlencode($gtm)));

        echo "\n<!-- AI Hub GTM (noscript) -->\n";
        $noscript = '<noscript><iframe src="%1$s" height="0" width="0" '
            . 'style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        printf($noscript, $iframeSrc);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageContext(): array
    {
        $objectId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        $postType = $objectId ? get_post_type($objectId) : null;
        $categories = [];

        if ($objectId && function_exists('wp_get_post_categories')) {
            $categories = array_map(
                static fn ($value): string => sanitize_text_field((string) $value),
                wp_get_post_categories($objectId, ['fields' => 'names'])
            );
        }

        return [
            'id' => $objectId ?: null,
            'type' => $postType ?: null,
            'isFrontPage' => function_exists('is_front_page') ? is_front_page() : false,
            'isSingular' => function_exists('is_singular') ? is_singular() : false,
            'categories' => $categories,
            'template' => function_exists('get_page_template_slug') ? get_page_template_slug($objectId) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSiteContext(): array
    {
        return [
            'name' => get_bloginfo('name'),
            'language' => get_bloginfo('language'),
            'siteId' => $this->settings->getSiteId() ?? '',
            'url' => home_url(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserContext(): array
    {
        if (!is_user_logged_in()) {
            return ['loggedIn' => false];
        }

        $user = wp_get_current_user();
        $roles = array_map('sanitize_key', $user->roles ?? []);

        return [
            'loggedIn' => true,
            'id' => $this->hashUserIdentifier((int) $user->ID),
            'roles' => $roles,
            'displayName' => $user->display_name,
        ];
    }

    private function hashUserIdentifier(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        return hash_hmac('sha256', (string) $userId, wp_salt('auth'));
    }

    private function renderSessionReplaySnippet(): void
    {
        if (!$this->settings->isSessionReplayEnabled() || !$this->consentGate->sessionReplayAllowed()) {
            return;
        }

        $projectKey = $this->settings->getSessionReplayProjectKey();
        if (!$projectKey) {
            return;
        }

        $host = $this->settings->getSessionReplayHost();
        $scriptSrc = rtrim($host, '/') . '/static/openreplay.js';
        if (function_exists('apply_filters')) {
            $scriptSrc = apply_filters('ai_hub_session_replay_script', $scriptSrc, $host);
        }
        $maskSelectors = $this->settings->getSessionReplayMaskSelectors();

        $projectKeyJs = esc_js($projectKey);
        $ingestJs = esc_js(rtrim($host, '/'));
        $scriptSrcAttr = esc_url($scriptSrc);

        $mergedSelectors = array_unique(array_merge(self::DEFAULT_MASK_SELECTORS, $maskSelectors));
        $selectorPayload = wp_json_encode($mergedSelectors) ?: '[]';

        $context = [
            'siteId' => $this->settings->getSiteId(),
            'page' => $this->buildPageContext(),
        ];
        if (function_exists('apply_filters')) {
            $context = apply_filters('ai_hub_session_replay_context', $context);
        }
        $contextPayload = wp_json_encode($context) ?: '{}';

        echo "\n<!-- AI Hub Session Replay -->\n";
        $snippet = '<script id="ai-hub-session-replay">'
            . 'window.OpenReplay=window.OpenReplay||function(){'
            . '(window.OpenReplay.q=window.OpenReplay.q||[]).push(arguments);};'
            . 'window.OpenReplay.l=1*new Date();'
            . '(function(w,d,s,src){var r=d.createElement(s);r.async=1;r.src=src;'
            . 'var h=d.getElementsByTagName(s)[0];h.parentNode.insertBefore(r,h);})'
            . '(window,document,"script","%1$s");'
            . 'window.OpenReplay("start",{"projectKey":"%2$s","ingestPoint":"%3$s"});'
            . '(%4$s).forEach(function(selector){try{window.OpenReplay("setMask",selector);}catch(e){}});'
            . 'window.OpenReplay("setMetadata",%5$s);'
            . '</script>' . "\n";

        printf(
            $snippet,
            $scriptSrcAttr,
            $projectKeyJs,
            $ingestJs,
            $selectorPayload,
            $contextPayload
        );
    }

    private function renderFeedbackWidget(): void
    {
        if (!$this->settings->isFeedbackWidgetEnabled() || !$this->consentGate->feedbackAllowed()) {
            return;
        }

        $widgetUrl = $this->settings->getFeedbackWidgetUrl();
        if (!$widgetUrl) {
            return;
        }

        $projectKey = $this->settings->getFeedbackProjectKey();

        echo "\n<!-- AI Hub Feedback Widget -->\n";
        printf(
            '<script src="%1$s" data-ai-hub-feedback="%2$s" data-ai-hub-site="%3$s" async defer></script>' . "\n",
            esc_url($widgetUrl),
            esc_attr((string) $projectKey),
            esc_attr($this->settings->getSiteId() ?? '')
        );
    }

    private function renderTrackingShim(): void
    {
        if (!$this->consentGate->analyticsAllowed() || !$this->hasAnalyticsConfigured()) {
            return;
        }

        $config = [
            'conversionEvent' => $this->settings->getConversionEventName(),
            'gtmContainerId' => $this->settings->getGtmContainerId(),
            'gaMeasurementId' => $this->settings->getGaMeasurementId(),
        ];

        $json = wp_json_encode($config);
        if (!$json) {
            $json = json_encode($config);
        }

        echo "\n<!-- AI Hub Conversion Bridge -->\n";
        $bridge = '<script id="ai-hub-tracking-shim">'
            . '(function(w,c){w.dataLayer=w.dataLayer||[];function pushEvent(evt,params){var payload=Object.assign({event:evt},params||{});'
            . 'if(w.dataLayer&&Array.isArray(w.dataLayer)){w.dataLayer.push(payload);}if(typeof w.gtag==="function"){w.gtag("event",evt,params||{});}}'
            . 'w.AIHubTracking={config:c,push:pushEvent,trackConversion:function(evt,params){pushEvent(evt||c.conversionEvent||"generate_lead",params);},'
            . 'consent:function(state){w.dataLayer=w.dataLayer||[];w.dataLayer.push(Object.assign({event:"gtm.consentUpdate"},state||{}));}};'
            . 'w.addEventListener("ai-hub:conversion",function(evt){pushEvent(evt.detail&&evt.detail.event||c.conversionEvent||"generate_lead",evt.detail&&evt.detail.params);});'
            . 'w.addEventListener("ai-hub:lead-submitted",function(evt){pushEvent(evt.detail&&evt.detail.event||c.conversionEvent||"generate_lead",evt.detail&&evt.detail.params);});'
            . '})(window,%1$s);</script>' . "\n";

        printf($bridge, $json ?: '{}');
    }
}
