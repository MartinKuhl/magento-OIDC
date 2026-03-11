<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Customer\Block;

use Magento\Customer\Block\Adminhtml\Edit\Tab\View;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Injects an "OIDC Provider" row into the Personal Information table
 * on the Magento admin Customer View tab.
 *
 * Targets: Magento\Customer\Block\Adminhtml\Edit\Tab\View
 */
class OidcInfoPlugin
{
    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /** @var RequestInterface */
    private readonly RequestInterface $request;

    /** @var Escaper */
    private readonly Escaper $escaper;

    /**
     * Constructor.
     *
     * @param UserProviderResource $userProviderResource
     * @param RequestInterface     $request
     * @param Escaper              $escaper
     */
    public function __construct(
        UserProviderResource $userProviderResource,
        RequestInterface $request,
        Escaper $escaper
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->request = $request;
        $this->escaper = $escaper;
    }

    /**
     * After the Customer View tab is rendered, append an OIDC Provider row to the Personal Information table.
     *
     * @param View   $subject
     * @param string $result
     */
    public function afterToHtml(View $subject, string $result): string
    {
        $customerId = (int) $this->request->getParam('id', 0);
        if ($customerId === 0) {
            return $result;
        }

        $info = $this->userProviderResource->getProviderInfo('customer', $customerId);
        $providerText = $info
            ? $this->escaper->escapeHtml($info['display_name'])
              . ' (' . $this->escaper->escapeHtml($info['created_at']) . ')'
            : 'none';

        $label = __('OIDC Provider');
        $row = '<tr>'
            . '<td class="label"><span>'
            . $this->escaper->escapeHtml((string) $label)
            . '</span></td>'
            . '<td class="value">' . $providerText . '</td>'
            . '</tr>';

        $needle = '</tbody>';
        $pos    = strrpos($result, $needle);
        if ($pos !== false) {
            $result = substr_replace($result, $row . $needle, $pos, strlen($needle));
        }

        return $result;
    }
}
