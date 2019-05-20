<?php

namespace Gogol\Invoices\Admin\Rules;

use Gogol\Admin\Models\Model as AdminModel;
use Gogol\Admin\Models\AdminRule;
use Admin;
use Ajax;

class ProcessInvoiceItemRule extends AdminRule
{
    /*
     * Allow events also in frontend
     */
    public $frontend = true;

    //On all events
    public function fire(AdminModel $row)
    {
        $this->reloadItemPrice($row);
    }

    private function reloadItemPrice($row)
    {
        if ( ! $row->price )
            $row->price = calculateWithoutVat($row->price_vat, $row->vat);

        if ( ! $row->price_vat )
            $row->price_vat = calculateWithVat($row->price, $row->vat);
    }
}