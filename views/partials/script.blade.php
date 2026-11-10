{{--
    The aDataForSeo front end. Dependency-free and stateless: jobs live in the
    database and are advanced by a queue worker, so the page is a viewer that
    polls while something runs. Text only ever goes in as text nodes (see h()).
--}}
<script>
(function () {
    'use strict';
@include('aDataForSeo::partials.script-core')
@include('aDataForSeo::partials.script-home')
@include('aDataForSeo::partials.script-pro')
@include('aDataForSeo::partials.script-settings')
@verbatim
    start();
})();
@endverbatim
</script>
