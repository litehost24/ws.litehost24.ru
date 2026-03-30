<?php

namespace App\Http\Controllers;

use App\Lists\pages\AboutCompanyList;
use App\Lists\pages\ContactList;
use App\Lists\pages\DocumentList;
use App\Lists\pages\MainPageList;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function showMainPage() {
        $list = MainPageList::traitList();
        return view('show', ['list' => $list]);
    }

    public function aboutCompany()
    {
        $list = AboutCompanyList::traitList();
        return view('about-company', ['list' => $list]);
    }

    public function contacts()
    {
        $list = ContactList::articleList();
        return view('contacts', ['list' => $list]);
    }

    public function documents()
    {
        $list = DocumentList::articleList();
        return view('documents', ['list' => $list]);
    }
}
