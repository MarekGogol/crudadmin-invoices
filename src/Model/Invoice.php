<?php

namespace Gogol\Invoices\Model;

use Gogol\Invoices\Admin\Buttons\CreateInvoiceFromProform;
use Gogol\Invoices\Admin\Buttons\CreateReturnFromInvoice;
use Gogol\Invoices\Admin\Buttons\SendInvoiceEmailButton;
use Gogol\Invoices\Admin\Layouts\InvoiceComponent;
use Gogol\Invoices\Admin\Rules\ProcessInvoiceRule;
use Gogol\Invoices\Traits\InvoiceProcessTrait;
use Gogol\Admin\Fields\Group;
use Gogol\Admin\Models\Model as AdminModel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;

class Invoice extends AdminModel
{
    use InvoiceProcessTrait;

    /*
     * Model created date, for ordering tables in database and in user interface
     */
    protected $migration_date = '2019-03-26 20:10:10';

    /*
     * Template name
     */
    protected $name = 'Faktúry';

    protected $publishable = false;
    protected $sortable = false;

    /*
     * Automatic form and database generation
     * @name - field name
     * @placeholder - field placeholder
     * @type - field type | string/text/editor/select/integer/decimal/file/password/date/datetime/time/checkbox/radio
     * ... other validation methods from laravel
     */
    public function fields($row)
    {
        return [
            'Nastavenia dokladu' => Group::fields([
                'type' => 'name:Typ dokladu|type:select|'.($row ? '' : 'required').'|max:20',
                'number' => 'name:Č. dokladu|removeFromForm|index|max:30',
                'return' => 'name:Dobropis k faktúre|belongsTo:invoices,'.$this->getPrefixes('invoice').':number|exists:invoices,id,type,invoice|component:setReturnField|required_if:type,return|hidden',
                'proform' => 'name:Proforma|belongsTo:invoices,id|invisible',
                'vs' => [ 'name' => 'Variabilný symbol', 'placeholder' => 'Zadajte variabilný symbol', 'required' => true, 'max' => 12, $this->vsRuleUnique($row) ],
                'payment_method' => 'name:Spôsob platby|type:select|default:sepa',
                Group::fields([
                    'payment_date' => 'name:Dátum splatnosti|type:date|format:d.m.Y|title:Vypočítava sa automatický od dátumu vytvorenia +('.getSettings('payment_term').' dní)',
                    'paid_at' => 'name:Zaplatené dňa|type:date|format:d.m.Y|title:Zadajte dátum zaplatenia faktúry',
                    'created_at' => 'name:Vystavené dňa|type:datetime|format:d.m.Y H:i:s|required|default:CURRENT_TIMESTAMP',
                ])->inline(),
                Group::fields([
                    'note' => 'name:Poznámka|type:text|hidden',
                    Group::fields([
                        'price' => 'name:Cena bez DPH (€)|type:decimal|required|default:0',
                        'price_vat' => 'name:Cena s DPH (€)|type:decimal|required|default:0',
                    ])->add('removeFromForm')->inline()
                ]),
                'pdf' => 'name:Doklad|type:file|extension:pdf|removeFromForm',
            ]),
            'Fakturačné údaje' => Group::fields([
                'client' => 'name:Klient|'.(config('invoices.allow_client', false) ? 'belongsTo:clients,company_name' : 'type:imaginary').'|hidden|canAdd',
                'Firemné údaje' => Group::half([
                    Group::fields([
                        'company_name' => 'name:Meno a priezivsko / Firma|fillBy:client|placeholder:Zadajte názov odoberateľa|required|max:90',
                        'email' => 'name:Email|fillBy:client|placeholder:Slúži pre odoslanie faktúry na email|email',
                    ])->inline(),
                    'company_id' => 'name:IČO|type:string|fillBy:client|placeholder:Zadajte IČO|hidden',
                    'tax_id' => 'name:DIČ|type:string|fillBy:client|placeholder:Zadajte DIČ|hidden',
                    'vat_id' => 'name:IČ DPH|type:string|fillBy:client|placeholder:Zadajte IČ DPH|hidden',
                ]),
                'Fakturačná adresa' => Group::half([
                    'city' => 'name:Mesto|fillBy:client|placeholder:Zadajte mesto|required|hidden|max:90',
                    'zipcode' => 'name:PSČ|fillBy:client|placeholder:Zadajte PSČ|default:080 01|required|hidden|max:90',
                    'street' => 'name:Ulica|fillBy:client|placeholder:Zadajte ulicu|required|hidden|max:90',
                    'country' => 'name:Štát|fillBy:client|type:select|default:'.$this->getDefaultLang().'|hidden|required|max:90',
                ]),
            ]),
            'email_sent' => 'name:Notifikácia|type:json|removeFromForm',
            'snapshot_sha' => 'name:SHA Dát fakúry|max:50|invisible',
        ];
    }

    public function options()
    {
        return [
            'type' => config('invoices.invoice_types'),
            'payment_method' => config('invoices.payment_methods'),
            'country' => config('invoices.countries'),
        ];
    }

    protected $settings = [
        'increments' => false,
        'autoreset' => false,
        'refresh_interval' => 3000,
        'buttons.insert' => 'Nový doklad',
        'title' => [
            'insert' => 'Nový doklad',
            'update' => 'Upravujete doklad č. :number',
        ],
        'grid' => [
            'default' => 'full',
            'disabled' => true,
        ],
        'columns' => [
            'number.before' => 'type',
            'company_name.name' => 'Odberateľ',
            'company_name.after' => 'vs',
            'vs.name' => 'VS.',
            'email.before' => 'payment_method',
            'email_sent.before' => 'pdf',
            'email_sent.encode' => false,
            'pdf.encode' => false,
        ],
    ];

    protected $rules = [
        ProcessInvoiceRule::class,
    ];

    protected $layouts = [
        InvoiceComponent::class,
    ];

    protected $buttons = [
        CreateInvoiceFromProform::class,
        CreateReturnFromInvoice::class,
        SendInvoiceEmailButton::class,
    ];

    protected $prefixes = [
        'invoice' => 'FV-',
        'return' => 'DP-',
        'proform' => 'PF-',
    ];

    public function scopeAdminRows($query)
    {
        $query->with('proformInvoice:id,proform_id,number,pdf,type');
    }

    public function setAdminAttributes($attributes)
    {
        $attributes['number'] = $this->number;

        $attributes['email_sent'] = '<i style="color: '.($this->isEmailChecked() ? 'green' : 'red').'" class="fa fa-'.($this->isEmailChecked() ? 'check' : 'times').'"></i>';

        $attributes['return_number'] = $this->return_id && $this->return ? $this->return->number : null;

        $attributes['pdf'] = '<a href="'.action('\Gogol\Invoices\Controllers\InvoiceController@generateInvoicePdf', $this->getKey()).'" target="_blank">Zobraziť doklad</a>';

        return $attributes;
    }

    public function getTypeNameAttribute()
    {
        if ( $this->type == 'invoice' )
            return 'Faktúra (daňový doklad) č.';

        if ( $this->type == 'return' )
            return 'Dobropis č.';

        if ( $this->type == 'proform' )
            return 'Proforma č.';
    }

    public function getNumberPrefixAttribute()
    {
        if ( array_key_exists($this->type, $this->prefixes) )
            return $this->prefixes[$this->type];

        return '';
    }

    public function getNumberAttribute($value)
    {
        return $this->numberPrefix . $value;
    }

    public function proformInvoice()
    {
        return $this->belongsTo(Invoice::class, 'id', 'proform_id')->where('type', 'invoice');
    }

    public function returnInvoice()
    {
        return $this->belongsTo(Invoice::class, 'id', 'return_id');
    }

    public function vsRuleUnique($row)
    {
        return Rule::unique('invoices')->ignore($row)->where(function($query) use($row) {
            $query->whereNull('deleted_at');

            if ( ! $row )
                return;

            //Also except invoice/proform
            if ( $row->proform_id )
                $query->where('id', '!=', $row->proform_id);
            else
                $query->where('proform_id', '!=', $row->getKey());
        });
    }

    public function isEmailChecked()
    {
        return is_array($this->email_sent) && in_array($this->email, $this->email_sent);
    }

    public function getPdf($regenerate = false)
    {
        //Regenerate invoice if needed
        $this->generatePDF(true, $regenerate);

        return $this->pdf;
    }

    public function getPrefixes($prefix = null)
    {
        if ( $prefix )
            return array_key_exists($prefix, $this->prefixes) ? $this->prefixes[$prefix] : '';

        return $this->prefixes;
    }

    protected function getDefaultLang()
    {
        if ( count(config('invoices.countries')) == 0 )
            return;

        return array_keys(config('invoices.countries'))[0];
    }
}