Name: x11vnc
Version: 0.9.12
Release: 2
Summary: A VNC Server for the current X11 session
License: GPL
Group: System/X11
Source: %{name}-%{version}.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-root

BuildRequires: autoconf
BuildRequires: automake
BuildRequires: libjpeg-devel
BuildRequires: openssl-devel
BuildRequires: libX11-devel
BuildRequires: libXTrap-devel
BuildRequires: libXdamage-devel
BuildRequires: libXext-devel
BuildRequires: libXfixes-devel
BuildRequires: libXinerama-devel
BuildRequires: libXrandr-devel
BuildRequires: libXtst-devel
BuildRequires: zlib-devel
BuildRequires: libXi-devel
BuildRequires: libXrender-devel

%description
x11vnc is to X Window System what WinVNC is to Windows, i.e. a server
which serves the current Xwindows desktop via RFB (VNC) protocol
to the user.

%package devel
Summary:      RFB Headers for x11vnc
Group:        Developement/Libraries
Requires: %{name} = %{version}

%description
x11vnc is to X Window System what WinVNC is to Windows, i.e. a server
which serves the current X Window System desktop via RFB (VNC)
protocol to the user.

%description devel
Header files for x11vnc rfb

%package devel2
Summary:      222 RFB Headers for x11vnc
Group:        2222 Developement/Libraries
Requires: %{name} = %{version}

%description devel2
22222 Header files for x11vnc rfb

%prep
%setup -n %{name}-%{version}

%build
# CFLAGS="$RPM_OPT_FLAGS" ./configure --prefix=%{_prefix}
%configure --without-avahi --without-macosx-native
make

%install
%{__rm} -rf %{buildroot}
%{__make} install DESTDIR="%{buildroot}"

%clean
%{__rm} -rf %{buildroot}

%files
%defattr(-,root,root,-)
%doc README x11vnc/ChangeLog
%{_bindir}/x11vnc
%{_mandir}/man1/x11vnc.1*
%{_datadir}/x11vnc/
%{_datadir}/applications/x11vnc.desktop

%files devel
%defattr(-,root,root,-)
%{_includedir}/rfb/
