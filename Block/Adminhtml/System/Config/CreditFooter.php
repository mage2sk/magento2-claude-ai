<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Panth\ClaudeAi\Block\Adminhtml\Credit;

/**
 * System-config field that renders the Claude AI credit footer at the
 * bottom of Stores → Configuration → Panth Extensions → Claude AI.
 *
 * Renders as a full-width row (no label / scope / inherit) using the same
 * Credit block + credit.phtml as every Claude AI admin page so all
 * surfaces show one consistent footer.
 */
class CreditFooter extends Field
{
    public function render(AbstractElement $element)
    {
        $html = '<tr id="row_' . $element->getHtmlId() . '">'
            . '<td colspan="5" class="value" style="padding:0;border:0;">'
            . $this->_getElementHtml($element)
            . '</td></tr>';
        return $html;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->getLayout()
            ->createBlock(Credit::class)
            ->setTemplate('Panth_ClaudeAi::credit.phtml')
            ->toHtml();
    }
}
