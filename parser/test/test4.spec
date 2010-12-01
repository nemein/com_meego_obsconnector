#
# spec file for package celt (Version 0.7.1)
#
# Copyright (c) 2010 SUSE LINUX Products GmbH, Nuernberg, Germany.
#
# All modifications and additions to the file contributed by third parties
# remain the property of their copyright owners, unless otherwise agreed
# upon. The license for this file, and modifications and additions to the
# file, is the same license as for the pristine package itself (unless the
# license for the pristine package is not an Open Source License, in which
# case the license is the MIT License). An "Open Source License" is a
# license that conforms to the Open Source Definition (Version 1.9)
# published by the Open Source Initiative.

# Please submit bugfixes or comments via http://bugs.opensuse.org/
#

# norootforbuild


Name:           celt
Version:        0.7.1
Release:        2
License:        BSD3c(or similar)
Group:          Productivity/Multimedia/Other
Summary:        Ultra-Low Delay Audio Codec
Url:            http://www.celt-codec.org/
Source:         http://downloads.xiph.org/releases/celt/%{name}-%{version}.tar.bz2
Source1:        baselibs.conf
BuildRequires:  libogg-devel pkgconfig
# Patch configure.ac to remove the "0" suffix from libcelt
BuildRoot:      %{_tmppath}/%{name}-%{version}-build

%description
The CELT codec is an experimental audio codec for use in low-delay
speech and audio communication.

%package -n libcelt-devel
License:        BSD3c(or similar)
Summary:        Ultra-Low Delay Audio Codec
Group:          Development/Libraries/C and C++
Requires:       libcelt0-0 = %{version} glibc-devel pkgconfig

%description -n libcelt-devel
The CELT codec is an experimental audio codec for use in low-delay
speech and audio communication.

%package -n libcelt0-0
License:        BSD3c(or similar)
Summary:        Ultra-Low Delay Audio Codec
Group:          System/Libraries

%description -n libcelt0-0
The CELT codec is an experimental audio codec for use in low-delay
speech and audio communication.

%prep
%setup -q
#%{?suse_update_config:%{suse_update_config -f config}}

%build
autoreconf -f -i
%configure\
	--disable-static\
	--with-pic
%{__make} %{?jobs:-j%jobs}

%install
make DESTDIR=%buildroot install
rm -f %{buildroot}%{_libdir}/*.la

%clean
rm -rf %buildroot

%post -n libcelt0-0 -p /sbin/ldconfig

%postun -n libcelt0-0 -p /sbin/ldconfig

%files
%defattr(-,root,root)
%doc README COPYING TODO
%{_bindir}/celt*

%files -n libcelt-devel
%defattr(-,root,root)
%dir %{_includedir}/%{name}
%{_includedir}/%{name}/*.h
%{_libdir}/*.so
%{_libdir}/pkgconfig/celt.pc

%files -n libcelt0-0
%defattr(-,root,root)
%{_libdir}/libcelt0.so.0*

%changelog
