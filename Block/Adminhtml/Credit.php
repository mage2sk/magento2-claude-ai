<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;

class Credit extends Template
{
    protected $_template = 'Panth_ClaudeAi::credit.phtml';

    public function getWebsiteUrl(): string
    {
        return 'https://kishansavaliya.com';
    }

    public function getEmailAddress(): string
    {
        return 'kishansavaliyakb@gmail.com';
    }

    public function getUpworkUrl(): string
    {
        return 'https://www.upwork.com/freelancers/~016dd1767321100e21';
    }

    public function getDeveloperName(): string
    {
        return 'Kishan Savaliya';
    }

    public function getCompanyName(): string
    {
        return 'Panth Infotech';
    }
}
