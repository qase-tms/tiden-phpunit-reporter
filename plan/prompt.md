I need to create Tiden PHPUnit reporter so Tiden could digest phpunit reports and wire it with Tiden's reporting/requirements system.

New package should be created here: /Users/avaganov/Projects/tiden-workspace/repos/phpunit
Draft repo allready created.

`app`:  /Users/avaganov/Projects/app
This is how phpunit integrated in `app` /Users/avaganov/Projects/tiden-workspace/repos/phpunit/plan/PHPUNIT_QASE_REPORTER.md

This is example of Qase TMS phpunit reporter: /Users/avaganov/Projects/qase-phpunit

At the end we need to integrate this reporter to qase tms APP (/Users/avaganov/Projects/app)

As the last step:
I allready tried to make intergration withoyt reporter here (https://github.com/qase-tms/app/pull/3865/changes). But quite a lot of code code could be removed from there if proper reporter package is available.
