<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Customer\Block;

use Magento\Customer\Block\Adminhtml\Edit\Tab\View;
use Magento\Framework\App\RequestInterface;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Injects an "OIDC Provider" row into the Personal Information table
 * on the Magento admin Customer View tab.
 *
 * Targets: Magento\Customer\Block\Adminhtml\Edit\Tab\View
 */
class OidcInfoPlugin
{
    private readonly UserProviderResource $userProviderResource;
    private readonly RequestInterface $request;

    public function __construct(
        UserProviderResource $userProviderResource,
        RequestInterface $request
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->request = $request;
    }

    /**
     * After the Customer View tab is rendered, append an OIDC Provider row
     * to the Personal Information table.
     */
    public function afterToHtml(View $subject, string $result): string
    {
        $customerId = (int) $this->request->getParam('id', 0);
        if ($customerId === 0) {
            return $result;
        }

        $info = $this->userProviderResource->getProviderInfo('customer', $customerId);
        $providerText = $info
            ? htmlspecialchars($info['display_name'], ENT_QUOTES, 'UTF-8')
              . ' (' . htmlspecialchars($info['created_at'], ENT_QUOTES, 'UTF-8') . ')'
            : 'none';

        $label = __('OIDC Provider');
        $row = '<tr>'
            . '<td class="label"><span>'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
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
