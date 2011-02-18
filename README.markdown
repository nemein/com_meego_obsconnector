openSUSE Build Service Connector library for PHP. Use this to interact with software packages managed using OBS.

=== Important configuration note ===

One part of the this software is an rpm xray tool (parser/RpmXray.php).
It uses the standard 'rpm' tool to gather information about RPM packages.
Rpm can also query packages via FTP or HTTP and this feature is used in
RpmXray.

If the server (with obsconnector installed) is behind a proxy then
additional configuration needed for rpm.

For details please refer to 'man rpm'.