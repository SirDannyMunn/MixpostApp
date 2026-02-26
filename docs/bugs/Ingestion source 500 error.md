{
    "message": "SQLSTATE[42883]: Undefined function: 7 ERROR:  operator does not exist: character varying = uuid\nLINE 1: ...oin \"ingestion_sources\" as \"i\" on \"i\".\"source_id\" = \"b\".\"id\"...\n                                                             ^\nHINT:  No operator matches the given name and argument types. You might need to add explicit type casts. (Connection: pgsql, SQL: select count(*) as aggregate from \"bookmarks\" as \"b\" left join \"ingestion_sources\" as \"i\" on \"i\".\"source_id\" = \"b\".\"id\" and \"i\".\"source_type\" = 'bookmark' where \"b\".\"organization_id\" = 019b31e7-8ff9-73e4-ac74-f9b72214bc31 and \"bookmarks\".\"deleted_at\" is null)",
    "exception": "Illuminate\\Database\\QueryException",
    "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php",
    "line": 826,
    "trace": [
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php",
            "line": 780,
            "function": "runQueryCallback",
            "class": "Illuminate\\Database\\Connection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php",
            "line": 397,
            "function": "run",
            "class": "Illuminate\\Database\\Connection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3188,
            "function": "select",
            "class": "Illuminate\\Database\\Connection",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3173,
            "function": "runSelect",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3763,
            "function": "Illuminate\\Database\\Query\\{closure}",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3172,
            "function": "onceWithColumns",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3367,
            "function": "get",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Query\\Builder.php",
            "line": 3326,
            "function": "runPaginationCountQuery",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Eloquent\\Builder.php",
            "line": 1120,
            "function": "getCountForPagination",
            "class": "Illuminate\\Database\\Query\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\app\\Http\\Controllers\\Api\\V1\\LibraryItemController.php",
            "line": 39,
            "function": "paginate",
            "class": "Illuminate\\Database\\Eloquent\\Builder",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Controller.php",
            "line": 54,
            "function": "index",
            "class": "App\\Http\\Controllers\\Api\\V1\\LibraryItemController",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\ControllerDispatcher.php",
            "line": 43,
            "function": "callAction",
            "class": "Illuminate\\Routing\\Controller",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php",
            "line": 265,
            "function": "dispatch",
            "class": "Illuminate\\Routing\\ControllerDispatcher",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Route.php",
            "line": 211,
            "function": "runController",
            "class": "Illuminate\\Routing\\Route",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 822,
            "function": "run",
            "class": "Illuminate\\Routing\\Route",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 180,
            "function": "Illuminate\\Routing\\{closure}",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\app\\Http\\Middleware\\EnsureOrganizationContext.php",
            "line": 56,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "App\\Http\\Middleware\\EnsureOrganizationContext",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\app\\Http\\Middleware\\DevAutoAuth.php",
            "line": 85,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "App\\Http\\Middleware\\DevAutoAuth",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Middleware\\SubstituteBindings.php",
            "line": 50,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Routing\\Middleware\\SubstituteBindings",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Middleware\\ThrottleRequests.php",
            "line": 166,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Middleware\\ThrottleRequests.php",
            "line": 129,
            "function": "handleRequest",
            "class": "Illuminate\\Routing\\Middleware\\ThrottleRequests",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Middleware\\ThrottleRequests.php",
            "line": 90,
            "function": "handleRequestUsingNamedLimiter",
            "class": "Illuminate\\Routing\\Middleware\\ThrottleRequests",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Routing\\Middleware\\ThrottleRequests",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Auth\\Middleware\\Authenticate.php",
            "line": 63,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Auth\\Middleware\\Authenticate",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 137,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 821,
            "function": "then",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 800,
            "function": "runRouteWithinStack",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 764,
            "function": "runRoute",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Router.php",
            "line": 753,
            "function": "dispatchToRoute",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 200,
            "function": "dispatch",
            "class": "Illuminate\\Routing\\Router",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 180,
            "function": "Illuminate\\Foundation\\Http\\{closure}",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\app\\Http\\Middleware\\DevAutoAuth.php",
            "line": 85,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "App\\Http\\Middleware\\DevAutoAuth",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\app\\Http\\Middleware\\LogRequests.php",
            "line": 15,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "App\\Http\\Middleware\\LogRequests",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest.php",
            "line": 21,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull.php",
            "line": 31,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\ConvertEmptyStringsToNull",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest.php",
            "line": 21,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\TrimStrings.php",
            "line": 51,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TransformsRequest",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\TrimStrings",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\ValidatePostSize.php",
            "line": 27,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\ValidatePostSize",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance.php",
            "line": 109,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Middleware\\PreventRequestsDuringMaintenance",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\HandleCors.php",
            "line": 61,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\HandleCors",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Http\\Middleware\\TrustProxies.php",
            "line": 58,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 219,
            "function": "handle",
            "class": "Illuminate\\Http\\Middleware\\TrustProxies",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php",
            "line": 137,
            "function": "Illuminate\\Pipeline\\{closure}",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 175,
            "function": "then",
            "class": "Illuminate\\Pipeline\\Pipeline",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Http\\Kernel.php",
            "line": 144,
            "function": "sendRequestThroughRouter",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        },
        {
            "file": "C:\\laragon\\www\\MixpostApp\\public\\index.php",
            "line": 51,
            "function": "handle",
            "class": "Illuminate\\Foundation\\Http\\Kernel",
            "type": "->"
        }
    ]
}