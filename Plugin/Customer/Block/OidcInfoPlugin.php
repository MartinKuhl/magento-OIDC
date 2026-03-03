<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Customer\Block;

use Magento\Customer\Block\Adminhtml\Edit\Tab\View;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Injects an "OIDC Provider" row into the Personal Information table
 * on the Magento admin Customer View tab.
 *
 * Targets: Magento\Customer\Block\Adminhtml\Edit\Tab\View
 */
class OidcInfoPlugin
{
    /** @var \MiniOrange\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /**
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(UserProviderResource $userProviderResource)
    {
        $this->userProviderResource = $userProviderResource;
    }

    /**
     * After the Customer View tab is rendered, inject an OIDC Provider row
     * into the Personal Information table.
     *
     * @param  View   $subject
     * @param  string $result  Rendered HTML of the tab
     * @return string
     */
    public function afterToHtml(View $subject, string $result): string
    {
        $customer = $subject->getCustomer();
        if (!$customer || !$customer->getId()) {
            return $result;
        }

        $info = $this->userProviderResource->getProviderInfo('customer', (int) $customer->getId());
        $providerText = $info
            ? htmlspecialchars($info['display_name'], ENT_QUOTES, 'UTF-8')
              . ' (' . htmlspecialchars($info['created_at'], ENT_QUOTES, 'UTF-8') . ')'
            : '—';

        $label = __('OIDC Provider');
        $row = '<tr>'
            . '<td class="label"><span>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</span></td>'
            . '<td class="value">' . $providerText . '</td>'
            . '</tr>';

        // Inject the row before the last </tbody></table> — the Personal Information table
        $needle = '</tbody></table>';
        $pos    = strrpos($result, $needle);
        if ($pos !== false) {
            $result = substr_replace($result, $row . $needle, $pos, strlen($needle));
        }

        return $result;
    }
}
