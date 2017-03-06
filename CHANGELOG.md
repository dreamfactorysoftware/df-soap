# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Added
### Changed
### Fixed

## [0.8.0] - 2017-03-03
- Major restructuring to upgrade to Laravel 5.4 and be more dynamically available

## [0.7.0] - 2017-01-16
### Changed
- Load WSDL from storage directory if only file name given

## [0.6.0] - 2016-11-17
### Fixed
- Do not let invalid wsdl config stop services from loading API Docs

## [0.5.0] - 2016-10-03
- DF-826 Updating to latest df-core models
- Updating types gleaned from documentation

## [0.4.2] - 2016-09-08
### Fixed
- Swagger generation from complexObjectArray type string search issue

## [0.4.1] - 2016-08-29
### Fixed
- Swagger generation needs identification from service so that SOAP functions are not reused

## [0.4.0] - 2016-08-21
### Changed
- General cleanup from declaration changes in df-core for service doc and providers

## [0.3.3] - 2016-07-28
### Fixed
- Improved conversion of SOAP types to Swagger definitions

## [0.3.2] - 2016-07-20
### Fixed
- Fix type support for decimal and anyType

## [0.3.1] - 2016-07-08
### Added
- DF-674 Swagger model generation corrected, now supports wsdl enumerations in types
- DF-775 Adding event support for SOAP methods

### Fixed
- SoapFault::faultcode not being passed thru in REST response
- Fixed an issue that breaks soap service when there are no headers specified
- DF-762 Adding soap fault code to soap exception

## [0.3.0] - 2016-05-27
### Added
- Ability to configure and use SoapHeaders, authentication and others

### Changed
- Moved seeding functionality to service provider to adhere to df-core changes.
- Licensing changed to support subscription plan, see latest [dreamfactory](https://github.com/dreamfactorysoftware/dreamfactory).

## [0.2.0] - 2016-01-29
### Added

### Changed
- **MAJOR** Updated code base to use OpenAPI (fka Swagger) Specification 2.0 from 1.2

### Fixed

## [0.1.1] - 2015-12-18
### Changed
- Sync up with changes in df-core for schema classes

## 0.1.0 - 2015-10-24
First official release working with the new [df-core](https://github.com/dreamfactorysoftware/df-core) library.

[Unreleased]: https://github.com/dreamfactorysoftware/df-soap/compare/0.8.0...HEAD
[0.8.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.5.0...0.6.0
[0.5.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.4.2...0.5.0
[0.4.2]: https://github.com/dreamfactorysoftware/df-soap/compare/0.4.1...0.4.2
[0.4.1]: https://github.com/dreamfactorysoftware/df-soap/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.3.3...0.4.0
[0.3.3]: https://github.com/dreamfactorysoftware/df-soap/compare/0.3.2...0.3.3
[0.3.2]: https://github.com/dreamfactorysoftware/df-soap/compare/0.3.1...0.3.2
[0.3.1]: https://github.com/dreamfactorysoftware/df-soap/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/dreamfactorysoftware/df-soap/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/dreamfactorysoftware/df-soap/compare/0.1.0...0.1.1
